#!/bin/bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT"

usage() {
  cat <<'TXT'
NADI macOS launcher

Usage:
  ./FINALIZE_AND_RUN_MAC.command --demo          Run local SQLite demo setup/server (default).
  ./FINALIZE_AND_RUN_MAC.command --release-gate Run the authoritative production release gate.
  ./FINALIZE_AND_RUN_MAC.command --doctor       Check production release-gate execution readiness.
  ./FINALIZE_AND_RUN_MAC.command --help         Show this help.

Important:
  --demo is NOT production verification and can never authorize FINAL PASS.
  Production release verification is delegated only to scripts/final-gate.sh.
TXT
}

MODE="${1:---demo}"
case "$MODE" in
  --release-gate)
    shift || true
    echo "Delegating to authoritative production release gate: scripts/final-gate.sh"
    exec bash scripts/final-gate.sh "$@"
    ;;
  --doctor)
    shift || true
    exec bash scripts/release-gate-doctor.sh "$@"
    ;;
  --help|-h)
    usage
    exit 0
    ;;
  --demo)
    shift || true
    if [[ $# -gt 0 ]]; then
      echo "ERROR: --demo does not accept additional arguments." >&2
      usage >&2
      exit 2
    fi
    ;;
  *)
    echo "ERROR: Unknown mode '$MODE'." >&2
    usage >&2
    exit 2
    ;;
esac

echo "============================================================"
echo " NADI LSP MIGAS — macOS Local Demo"
echo "============================================================"
echo "WARNING: LOCAL DEMO ONLY. This path does not execute or satisfy FINAL release gates."

if [ "$(uname -s)" != "Darwin" ]; then
  echo "ERROR: Local demo launcher is intended for macOS."
  exit 1
fi

echo "Architecture: $(uname -m)"

command -v php >/dev/null 2>&1 || { echo "ERROR: PHP belum tersedia."; exit 1; }
command -v composer >/dev/null 2>&1 || { echo "ERROR: Composer belum tersedia."; exit 1; }
command -v node >/dev/null 2>&1 || { echo "ERROR: Node.js belum tersedia."; exit 1; }
command -v npm >/dev/null 2>&1 || { echo "ERROR: npm belum tersedia."; exit 1; }

echo "Running local SQLite runtime preflight..."
bash scripts/runtime-preflight.sh --sqlite

echo "[1/7] Installing PHP dependencies from composer.lock..."
composer install --no-interaction --prefer-dist

echo "[2/7] Installing frontend dependencies from package-lock.json..."
rm -rf node_modules
npm ci --no-audit --no-fund

DEMO_PASSWORD="$(php -r 'echo "Nadi!".bin2hex(random_bytes(8))."Aa9";')"
export NADI_LOCAL_DEMO_PASSWORD="$DEMO_PASSWORD"

if [ ! -f .env ]; then
  cp .env.example .env
  echo "Created .env untuk local demo."
fi

php -r '
  $f=".env"; $s=file_get_contents($f);
  $repl=[
    "APP_ENV"=>"local",
    "APP_DEBUG"=>"true",
    "APP_URL"=>"http://127.0.0.1:8000",
    "DB_CONNECTION"=>"sqlite",
    "SESSION_DRIVER"=>"file",
    "CACHE_STORE"=>"file",
    "QUEUE_CONNECTION"=>"sync",
    "VITE_DEMO_MODE"=>"true",
    "NADI_ALLOW_DEMO_SEED"=>"true",
    "NADI_DEMO_PASSWORD"=>getenv("NADI_LOCAL_DEMO_PASSWORD") ?: ""
  ];
  foreach($repl as $k=>$v){
    if(preg_match("/^".preg_quote($k,"/")."=.*/m",$s)){
      $s=preg_replace("/^".preg_quote($k,"/")."=.*/m",$k."=".$v,$s);
    } else { $s.="\n".$k."=".$v; }
  }
  file_put_contents($f,$s);
'

mkdir -p database storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache
[ -f database/database.sqlite ] || touch database/database.sqlite

if ! grep -q '^APP_KEY=base64:' .env; then
  php artisan key:generate --force
fi

echo "[3/7] Frontend production-format build for local demo..."
npm run build

if [ ! -f public/build/manifest.json ]; then
  echo "ERROR: Frontend build selesai tetapi public/build/manifest.json tidak ditemukan."
  exit 1
fi

echo "[4/7] Local demo database migration + synthetic data..."
php artisan migrate:fresh --seed --force

echo "[5/7] Laravel test suite in local demo environment..."
php artisan test

echo "[6/7] Clearing generated caches..."
php artisan optimize:clear

echo "[7/7] Local non-production readiness check..."
php artisan nadi:release-check

echo
echo "NADI local demo siap dijalankan pada http://127.0.0.1:8000"
echo "Mode: LOCAL DEMO (SQLite + synthetic data). Bukan production acceptance."
echo "Untuk production verification gunakan: ./FINALIZE_AND_RUN_MAC.command --release-gate"
echo "Password demo lokal (dibuat acak untuk sesi setup ini): $DEMO_PASSWORD"
echo
php artisan serve --host=127.0.0.1 --port=8000
