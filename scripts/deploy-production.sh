#!/usr/bin/env bash
set -euo pipefail

if [[ "${APP_ENV:-}" != "production" && ! -f .env ]]; then
  echo "Production .env must exist or APP_ENV=production must be injected before deployment." >&2
  exit 2
fi

: "${NADI_DEPLOYMENT_INTAKE_RECEIPT:?NADI_DEPLOYMENT_INTAKE_RECEIPT is required}"
: "${NADI_EXPECTED_GITHUB_REPOSITORY:?NADI_EXPECTED_GITHUB_REPOSITORY is required}"
: "${NADI_POST_DEPLOY_BASE_URL:?NADI_POST_DEPLOY_BASE_URL is required}"

command -v php >/dev/null || { echo "php is required" >&2; exit 2; }
command -v composer >/dev/null || { echo "composer is required" >&2; exit 2; }
command -v curl >/dev/null || { echo "curl is required" >&2; exit 2; }

printf '\n[1/11] Authorized production deployment intake\n'
php scripts/verify_deployment_intake.php "$NADI_DEPLOYMENT_INTAKE_RECEIPT"

printf '\n[2/11] Release artifact integrity\n'
php scripts/verify_release_manifest.php

printf '\n[3/11] Runtime/MySQL preflight\n'
bash scripts/runtime-preflight.sh --mysql --runtime-only

printf '\n[4/11] Installing production PHP dependencies\n'
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

printf '\n[5/11] Pre-maintenance production/build validation\n'
php artisan nadi:release-check --production --skip-db

printf '\n[6/11] Maintenance mode\n'
php artisan down --retry=60
maintenance_entered=1
on_exit() {
  local code=$?
  if [[ $code -ne 0 && ${maintenance_entered:-0} -eq 1 ]]; then
    echo "Deployment failed while NADI is in maintenance mode. Traffic remains disabled to prevent serving an unaccepted or partially migrated release." >&2
    echo "Review deployment/post-deploy evidence, database state, and backup. Complete the forward deployment or restore the previous release/database according to the runbook before running 'php artisan up'." >&2
  fi
}
trap on_exit EXIT

printf '\n[7/11] Database migration\n'
php artisan migrate --force

printf '\n[8/11] Cache refresh\n'
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

printf '\n[9/11] Final production readiness check\n'
php artisan nadi:release-check --production

printf '\n[10/11] Application online\n'
php artisan up
maintenance_entered=0

printf '\n[11/11] Post-deploy verification\n'
if ! bash scripts/post_deploy_verify.sh; then
  echo "Post-deploy verification failed. Returning NADI to maintenance mode; no automatic schema rollback will be attempted." >&2
  php artisan down --retry=60 || true
  maintenance_entered=1
  exit 1
fi

trap - EXIT
echo "Deployment and post-deploy verification completed successfully. Preserve DEPLOYMENT_INTAKE.json and POST_DEPLOY_VERIFICATION.json with the release records."
