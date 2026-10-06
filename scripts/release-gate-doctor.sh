#!/usr/bin/env bash
set -euo pipefail

if [[ ! -f artisan || ! -f composer.json || ! -f package-lock.json || ! -f config/release-environment.json ]]; then
  echo "Run this script from the NADI repository root." >&2
  exit 2
fi

if [[ $# -ne 0 ]]; then
  echo "Usage: bash scripts/release-gate-doctor.sh" >&2
  exit 2
fi

if ! command -v python3 >/dev/null 2>&1; then
  echo "FAIL command python3 is required to evaluate the release-host contract" >&2
  exit 1
fi

args=(snapshot)
if [[ -n "${NADI_RELEASE_ENV_EVIDENCE:-}" ]]; then
  args+=(--output "$NADI_RELEASE_ENV_EVIDENCE")
fi

exec python3 scripts/release_environment.py "${args[@]}"
