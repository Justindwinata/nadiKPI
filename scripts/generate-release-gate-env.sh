#!/usr/bin/env bash
set -euo pipefail

FORMAT="shell"
DATABASE="${DB_DATABASE:-}"
RUN_ID="local"
DOMAIN="ci.invalid"

usage() {
  cat <<'EOF'
Usage: scripts/generate-release-gate-env.sh [options]

Generate only ephemeral release-gate credentials; database connection secrets are
never generated or printed by this script.

Options:
  --format shell|github-env   Output syntax (default: shell)
  --database NAME            Verification database (default: DB_DATABASE)
  --run-id VALUE             Non-secret identity suffix (default: local)
  --domain DOMAIN            Email domain (default: ci.invalid)
EOF
}

while (($#)); do
  case "$1" in
    --format) FORMAT="${2:-}"; shift 2 ;;
    --database) DATABASE="${2:-}"; shift 2 ;;
    --run-id) RUN_ID="${2:-}"; shift 2 ;;
    --domain) DOMAIN="${2:-}"; shift 2 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown argument: $1" >&2; usage >&2; exit 2 ;;
  esac
done

if [[ "$FORMAT" != "shell" && "$FORMAT" != "github-env" ]]; then
  echo "FAIL: --format must be shell or github-env" >&2
  exit 2
fi
if [[ -z "$DATABASE" ]]; then
  echo "FAIL: DB_DATABASE or --database is required" >&2
  exit 2
fi
database_normalized="${DATABASE,,}"
if [[ ! "$database_normalized" =~ (^|[_-])(test|testing|ci|verify|verification)($|[_-]) ]]; then
  echo "FAIL: refusing to generate destructive acknowledgement for non-verification database '$DATABASE'" >&2
  exit 1
fi
if [[ ! "$DOMAIN" =~ ^[A-Za-z0-9.-]+$ ]]; then
  echo "FAIL: invalid email domain" >&2
  exit 2
fi

python3 - "$FORMAT" "$DATABASE" "$RUN_ID" "$DOMAIN" <<'PY'
import base64, re, secrets, sys
fmt, database, run_id, domain = sys.argv[1:]
safe_id = re.sub(r"[^A-Za-z0-9-]+", "-", run_id).strip("-") or "local"

def password(prefix: str) -> str:
    return prefix + secrets.token_hex(16)

values = {
    "APP_KEY": "base64:" + base64.b64encode(secrets.token_bytes(32)).decode(),
    "NADI_VERIFY_DESTRUCTIVE_DATABASE": database,
    "NADI_ACCEPTANCE_EMAIL": f"acceptance-{safe_id}@{domain}",
    "NADI_ACCEPTANCE_PASSWORD": password("Aa1!"),
    "NADI_BROWSER_FORCED_EMAIL": f"forced-{safe_id}@{domain}",
    "NADI_BROWSER_FORCED_PASSWORD": password("Bb2!"),
    "NADI_BROWSER_FORCED_NEW_PASSWORD": password("Cc3!"),
    "NADI_BROWSER_VIEWER_EMAIL": f"viewer-{safe_id}@{domain}",
    "NADI_BROWSER_VIEWER_PASSWORD": password("Dd4!"),
    "NADI_BROWSER_VIEWER_NEW_PASSWORD": password("Ee5!"),
}
for key, value in values.items():
    if fmt == "shell":
        # Generated values contain only a restricted safe character set; single-quote
        # anyway so the output can be sourced without interpretation.
        print(f"export {key}='{value}'")
    else:
        print(f"{key}={value}")
PY
