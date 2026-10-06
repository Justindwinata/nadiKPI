#!/usr/bin/env bash
set -euo pipefail

DB_MODE="mysql"
REQUIRE_BUILD_TOOLS=1

for arg in "$@"; do
  case "$arg" in
    --sqlite) DB_MODE="sqlite" ;;
    --mysql) DB_MODE="mysql" ;;
    --runtime-only) REQUIRE_BUILD_TOOLS=0 ;;
    --with-build-tools) REQUIRE_BUILD_TOOLS=1 ;;
    -h|--help)
      echo "Usage: $0 [--mysql|--sqlite] [--runtime-only|--with-build-tools]"
      exit 0
      ;;
    *)
      echo "Unknown argument: $arg" >&2
      echo "Usage: $0 [--mysql|--sqlite] [--runtime-only|--with-build-tools]" >&2
      exit 2
      ;;
  esac
done

fail=0
check_cmd() {
  local cmd="$1"
  if command -v "$cmd" >/dev/null 2>&1; then
    echo "PASS command $cmd"
  else
    echo "FAIL command $cmd"
    fail=1
  fi
}

check_ext() {
  local ext="$1"
  if php -r "exit(extension_loaded('$ext') ? 0 : 1);"; then
    echo "PASS PHP extension $ext"
  else
    echo "FAIL PHP extension $ext"
    fail=1
  fi
}

check_cmd php
check_cmd composer
if (( REQUIRE_BUILD_TOOLS )); then
  check_cmd node
  check_cmd npm
fi

if command -v php >/dev/null 2>&1; then
  if php -r 'exit(version_compare(PHP_VERSION, "8.4.1", ">=") ? 0 : 1);'; then
    echo "PASS PHP >= 8.4.1 ($(php -r 'echo PHP_VERSION;'))"
  else
    echo "FAIL PHP >= 8.4.1 required ($(php -r 'echo PHP_VERSION;'))"
    fail=1
  fi
  for ext in ctype fileinfo filter hash json libxml mbstring openssl PDO session tokenizer dom xml xmlwriter; do
    check_ext "$ext"
  done
  if [[ "$DB_MODE" == "mysql" ]]; then
    check_ext pdo_mysql
  else
    check_ext pdo_sqlite
  fi
fi

if (( REQUIRE_BUILD_TOOLS )) && command -v node >/dev/null 2>&1; then
  if node -e 'const [M,m,p]=process.versions.node.split(".").map(Number); const ok=(M===20 && (m>19 || (m===19 && p>=0))) || (M===22 && (m>12 || (m===12 && p>=0))) || M>22; process.exit(ok?0:1)'; then
    echo "PASS Node engine $(node -v)"
  else
    echo "FAIL Node must satisfy ^20.19.0 || >=22.12.0 (got $(node -v))"
    fail=1
  fi
fi

if (( REQUIRE_BUILD_TOOLS )) && command -v npm >/dev/null 2>&1; then
  npm_major="$(npm -v | cut -d. -f1)"
  if [[ "$npm_major" =~ ^[0-9]+$ ]] && (( npm_major >= 10 )); then
    echo "PASS npm >=10 ($(npm -v))"
  else
    echo "FAIL npm >=10 required ($(npm -v))"
    fail=1
  fi
fi

if (( fail )); then
  mode="runtime-only"
  (( REQUIRE_BUILD_TOOLS )) && mode="build+runtime"
  echo "NADI runtime preflight: FAIL ($DB_MODE, $mode)"
  exit 1
fi

mode="runtime-only"
(( REQUIRE_BUILD_TOOLS )) && mode="build+runtime"
echo "NADI runtime preflight: PASS ($DB_MODE, $mode)"
