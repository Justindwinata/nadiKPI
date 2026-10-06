#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

cat <<'MSG'
NADI release verification compatibility entrypoint.
The authoritative release pipeline is scripts/final-gate.sh.
Delegating without skipping or weakening any mandatory gate.
MSG

exec bash scripts/final-gate.sh "$@"
