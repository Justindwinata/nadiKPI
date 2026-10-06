#!/usr/bin/env bash
set -euo pipefail

if [[ ! -f artisan || ! -f composer.json || ! -f package-lock.json ]]; then
  echo "Run this script from the NADI repository root." >&2
  exit 2
fi

ARTIFACT_ROOT="${NADI_GATE_ARTIFACT_DIR:-artifacts/release-gate}"
BROWSER_ARTIFACT_DIR="${NADI_BROWSER_ARTIFACT_DIR:-artifacts/browser-acceptance}"
PACKAGE_VERSION="${NADI_GATE_PACKAGE_VERSION:-ci-gate}"
PACKAGE_DIR_A="${NADI_GATE_PACKAGE_DIR_A:-dist-gate-a}"
PACKAGE_DIR_B="${NADI_GATE_PACKAGE_DIR_B:-dist-gate-b}"
SERVER_LOG="${NADI_GATE_SERVER_LOG:-/tmp/nadi-final-gate-server.log}"
SERVER_PID=""
CURRENT_PHASE="initialization"
mkdir -p "$ARTIFACT_ROOT"

write_failure_evidence() {
  local exit_code=$?
  if [[ -n "$SERVER_PID" ]]; then
    kill "$SERVER_PID" >/dev/null 2>&1 || true
  fi
  if [[ -s "$SERVER_LOG" ]]; then
    echo "--- NADI server log (failure context) ---" >&2
    tail -200 "$SERVER_LOG" >&2 || true
  fi
  if command -v python3 >/dev/null 2>&1; then
    python3 - "$ARTIFACT_ROOT/final_gate.json" "$CURRENT_PHASE" "$exit_code" "$ARTIFACT_ROOT/runtime_contract.json" "$ARTIFACT_ROOT/runtime_environment.json" <<'PY'
import hashlib, json, sys
from datetime import datetime, timezone
from pathlib import Path
path=Path(sys.argv[1])
path.parent.mkdir(parents=True, exist_ok=True)
related={}
for label, raw in (("runtime_contract", sys.argv[4]), ("runtime_environment", sys.argv[5])):
    evidence=Path(raw)
    if evidence.is_file():
        related[label]={
            "path":str(evidence),
            "sha256":hashlib.sha256(evidence.read_bytes()).hexdigest(),
        }
payload={
  "schema":"nadi.final-gate.v1",
  "status":"fail",
  "failed_phase":sys.argv[2],
  "exit_code":int(sys.argv[3]),
  "finished_at_utc":datetime.now(timezone.utc).isoformat(),
}
if related:
    payload["related_evidence"]=related
path.write_text(json.dumps(payload, indent=2, sort_keys=True)+"\n")
PY
  else
    # Keep failure evidence available even when Python itself is the missing prerequisite.
    printf '{\n  "schema": "nadi.final-gate.v1",\n  "status": "fail",\n  "failed_phase": "%s",\n  "exit_code": %s\n}\n' \
      "$CURRENT_PHASE" "$exit_code" > "$ARTIFACT_ROOT/final_gate.json"
  fi
  echo "NADI final gate: FAIL at phase '$CURRENT_PHASE' (exit=$exit_code)" >&2
  exit "$exit_code"
}
trap write_failure_evidence ERR INT TERM

if [[ $# -ne 0 ]]; then
  CURRENT_PHASE="00-argument-validation"
  echo "FAIL: scripts/final-gate.sh does not accept skip/partial arguments." >&2
  false
fi

phase() {
  CURRENT_PHASE="$1"
  printf '\n============================================================\n'
  printf 'NADI FINAL GATE — %s\n' "$CURRENT_PHASE"
  printf '============================================================\n'
}

sha256_file() {
  local path="$1"
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$path" | awk '{print $1}'
  elif command -v shasum >/dev/null 2>&1; then
    shasum -a 256 "$path" | awk '{print $1}'
  else
    echo "FAIL: no SHA-256 provider available." >&2
    return 1
  fi
}

stop_server() {
  if [[ -n "$SERVER_PID" ]]; then
    kill "$SERVER_PID" >/dev/null 2>&1 || true
    wait "$SERVER_PID" >/dev/null 2>&1 || true
    SERVER_PID=""
  fi
}

phase "00-execution-readiness-doctor"
python3 scripts/source_freeze.py verify-source --repo-root .
python3 scripts/release_closure_matrix.py validate --repo-root .
python3 scripts/release_environment.py contract --output "$ARTIFACT_ROOT/runtime_contract.json" --quiet
NADI_RELEASE_ENV_EVIDENCE="$ARTIFACT_ROOT/runtime_environment.json" \
  bash scripts/release-gate-doctor.sh

phase "01-runtime-preflight"
bash scripts/runtime-preflight.sh --mysql

phase "02-contract-audit"
python3 scripts/audit_frontend_api_contract.py

phase "03-clean-composer-install"
rm -rf vendor
composer install --no-interaction --prefer-dist --optimize-autoloader

phase "04-clean-npm-install"
rm -rf node_modules
npm ci --no-audit --no-fund

phase "05-real-vite-build"
rm -rf public/build
npm run build
test -f public/build/manifest.json

phase "06-mysql-runtime"
php scripts/assert_mysql_runtime.php
php scripts/assert_verification_database.php

phase "07-clean-test-database"
php artisan migrate:fresh --force

phase "08-phpunit-mysql"
NADI_REQUIRE_TEST_DB=mysql php artisan test

phase "09-pint"
./vendor/bin/pint --test

phase "10-production-readiness"
env \
  APP_ENV=production APP_DEBUG=false APP_URL=https://nadi.example.invalid \
  CACHE_STORE=database SESSION_DRIVER=database SESSION_ENCRYPT=true SESSION_SECURE_COOKIE=true \
  QUEUE_CONNECTION=database VITE_DEMO_MODE=false NADI_ALLOW_DEMO_SEED=false \
  php artisan nadi:release-check --production
php artisan route:cache
php artisan route:list --path=api >/dev/null
php artisan route:clear
php artisan view:cache
php artisan view:clear

# Isolation boundary: acceptance always starts from a fresh, explicitly acknowledged
# verification database after PHPUnit has finished, so test residue cannot influence it.
phase "11-acceptance-isolation-reset"
php scripts/assert_verification_database.php
php artisan migrate:fresh --force

phase "12-provision-ephemeral-acceptance-identities"
php artisan nadi:create-admin "$NADI_ACCEPTANCE_EMAIL" --name="NADI Release Acceptance" --password="$NADI_ACCEPTANCE_PASSWORD"
php artisan nadi:create-admin "$NADI_BROWSER_FORCED_EMAIL" --name="NADI Forced Password Acceptance" --password="$NADI_BROWSER_FORCED_PASSWORD" --force-password-change

phase "13-start-production-like-server"
rm -rf "$BROWSER_ARTIFACT_DIR"
mkdir -p "$BROWSER_ARTIFACT_DIR"
: > "$SERVER_LOG"
env \
  APP_ENV=production APP_DEBUG=false APP_URL=https://nadi.example.invalid \
  CACHE_STORE=database SESSION_DRIVER=database SESSION_ENCRYPT=true SESSION_SECURE_COOKIE=false \
  QUEUE_CONNECTION=database VITE_DEMO_MODE=false NADI_ALLOW_DEMO_SEED=false \
  NADI_TRUSTED_PROXIES=127.0.0.1 \
  php artisan serve --host=127.0.0.1 --port=8000 >"$SERVER_LOG" 2>&1 &
SERVER_PID=$!
for _ in {1..30}; do
  if curl -fsS http://127.0.0.1:8000/up >/dev/null 2>&1; then
    break
  fi
  sleep 1
done
if ! kill -0 "$SERVER_PID" >/dev/null 2>&1; then
  cat "$SERVER_LOG" >&2 || true
  echo "FAIL: production-like NADI server exited before acceptance." >&2
  false
fi

phase "14-http-acceptance"
NADI_ACCEPTANCE_BASE_URL="${NADI_ACCEPTANCE_BASE_URL:-http://127.0.0.1:8000}" \
NADI_ACCEPTANCE_HOST_HEADER="${NADI_ACCEPTANCE_HOST_HEADER:-nadi.example.invalid}" \
NADI_ACCEPTANCE_FORWARDED_PROTO="${NADI_ACCEPTANCE_FORWARDED_PROTO:-https}" \
bash scripts/http_acceptance.sh

phase "15-browser-acceptance"
NADI_BROWSER_ARTIFACT_DIR="$BROWSER_ARTIFACT_DIR" \
NADI_ACCEPTANCE_BASE_URL="${NADI_ACCEPTANCE_BASE_URL:-http://127.0.0.1:8000}" \
NADI_ACCEPTANCE_HOST_HEADER="${NADI_ACCEPTANCE_HOST_HEADER:-nadi.example.invalid}" \
python3 scripts/browser_acceptance.py

phase "16-acceptance-evidence-integrity"
python3 scripts/acceptance_evidence.py generate --artifact-dir "$BROWSER_ARTIFACT_DIR"
python3 scripts/acceptance_evidence.py verify --artifact-dir "$BROWSER_ARTIFACT_DIR"
stop_server

# Successful acceptance evidence is now self-contained and source-bound. Remove the
# ephemeral acceptance identities/reports by resetting only the guarded verification DB.
phase "17-post-acceptance-isolation-cleanup"
php scripts/assert_verification_database.php
php artisan migrate:fresh --force

phase "18-deterministic-real-build-package"
rm -rf "$PACKAGE_DIR_A" "$PACKAGE_DIR_B"
python3 scripts/package_release.py --version="$PACKAGE_VERSION" --output-dir="$PACKAGE_DIR_A"
python3 scripts/package_release.py --version="$PACKAGE_VERSION" --output-dir="$PACKAGE_DIR_B"
ZIP_A="$PACKAGE_DIR_A/NADI-LSP-MIGAS-$PACKAGE_VERSION.zip"
ZIP_B="$PACKAGE_DIR_B/NADI-LSP-MIGAS-$PACKAGE_VERSION.zip"
unzip -t "$ZIP_A"
unzip -t "$ZIP_B"
cmp "$ZIP_A" "$ZIP_B"

phase "19-extracted-closed-world-verification"
EXTRACT_DIR="$(mktemp -d)"
unzip -q "$ZIP_A" -d "$EXTRACT_DIR"
php "$EXTRACT_DIR/scripts/verify_release_manifest.php"
printf '<?php echo "unexpected"; ?>' > "$EXTRACT_DIR/public/unexpected.php"
if php "$EXTRACT_DIR/scripts/verify_release_manifest.php"; then
  echo "FAIL: closed-world verifier accepted unexpected extracted file." >&2
  false
fi
rm -f "$EXTRACT_DIR/public/unexpected.php"
php "$EXTRACT_DIR/scripts/verify_release_manifest.php"
rm -rf "$EXTRACT_DIR"

phase "20-final-gate-evidence"
ZIP_SHA="$(sha256_file "$ZIP_A")"
EVIDENCE_SHA="$(sha256_file "$BROWSER_ARTIFACT_DIR/ACCEPTANCE_EVIDENCE_MANIFEST.json")"
SOURCE_SHA="$(sha256_file AUDITED_SOURCE_RC_MANIFEST.sha256)"
RUNTIME_ENV_SHA="$(sha256_file "$ARTIFACT_ROOT/runtime_environment.json")"
RUNTIME_CONTRACT_SHA="$(sha256_file "$ARTIFACT_ROOT/runtime_contract.json")"
python3 - "$ARTIFACT_ROOT/final_gate.json" "$ZIP_A" "$ZIP_SHA" "$EVIDENCE_SHA" "$SOURCE_SHA" "$RUNTIME_ENV_SHA" "$RUNTIME_CONTRACT_SHA" <<'PY'
import json, sys
from datetime import datetime, timezone
from pathlib import Path
out=Path(sys.argv[1]); out.parent.mkdir(parents=True, exist_ok=True)
out.write_text(json.dumps({
  "schema":"nadi.final-gate.v1",
  "status":"pass",
  "finished_at_utc":datetime.now(timezone.utc).isoformat(),
  "runtime_gates":{
    "clean_composer_install":"pass",
    "clean_npm_ci":"pass",
    "real_vite_build":"pass",
    "mysql8_migrate_fresh":"pass",
    "phpunit_mysql":"pass",
    "pint":"pass",
    "production_release_check":"pass",
    "http_acceptance":"pass",
    "browser_acceptance":"pass",
    "acceptance_isolation_cleanup":"pass",
    "deterministic_packaging":"pass",
    "extracted_closed_world_verification":"pass",
  },
  "gate_package":{"path":sys.argv[2],"sha256":sys.argv[3]},
  "acceptance_evidence_manifest_sha256":sys.argv[4],
  "source_manifest_sha256":sys.argv[5],
  "runtime_environment_evidence_sha256":sys.argv[6],
  "runtime_environment_contract_sha256":sys.argv[7],
}, indent=2, sort_keys=True)+"\n")
PY

CURRENT_PHASE="complete"
trap - ERR INT TERM
printf '\nNADI FINAL RELEASE GATE: PASS\n'
printf 'Gate package: %s\n' "$ZIP_A"
printf 'SHA-256: %s\n' "$ZIP_SHA"
printf 'Evidence: %s\n' "$ARTIFACT_ROOT/final_gate.json"
