#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${NADI_ACCEPTANCE_BASE_URL:-http://127.0.0.1:8000}"
EMAIL="${NADI_ACCEPTANCE_EMAIL:-}"
PASSWORD="${NADI_ACCEPTANCE_PASSWORD:-}"
HOST_HEADER="${NADI_ACCEPTANCE_HOST_HEADER:-}"
FORWARDED_PROTO="${NADI_ACCEPTANCE_FORWARDED_PROTO:-}"
ALLOW_UNAUTH_ONLY="${NADI_ACCEPTANCE_ALLOW_UNAUTHENTICATED_ONLY:-false}"
CURL_COMMON=()
if [[ -n "$HOST_HEADER" ]]; then CURL_COMMON+=(-H "Host: $HOST_HEADER"); fi
if [[ -n "$FORWARDED_PROTO" ]]; then CURL_COMMON+=(-H "X-Forwarded-Proto: $FORWARDED_PROTO"); fi
TMP_DIR="$(mktemp -d)"
COOKIE_JAR="$TMP_DIR/cookies.txt"
ROOT_HTML="$TMP_DIR/root.html"
HEADERS="$TMP_DIR/headers.txt"
trap 'rm -rf "$TMP_DIR"' EXIT

request_status() {
  local method="$1" url="$2" output="$3"
  shift 3
  curl -sS -X "$method" -o "$output" -w '%{http_code}' "${CURL_COMMON[@]}" "$@" "$url"
}

expect_status() {
  local expected="$1" actual="$2" label="$3"
  if [[ "$actual" != "$expected" ]]; then
    echo "FAIL: $label expected HTTP $expected, got $actual" >&2
    if [[ -f "$TMP_DIR/last-body" ]]; then cat "$TMP_DIR/last-body" >&2 || true; fi
    exit 1
  fi
  echo "PASS: $label -> HTTP $actual"
}

printf '\n[NADI acceptance] Public/runtime endpoints\n'
status="$(request_status GET "$BASE_URL/" "$ROOT_HTML")"
expect_status 200 "$status" "SPA root"
grep -q 'id="app"' "$ROOT_HTML" || { echo 'FAIL: SPA root does not contain #app mount point' >&2; exit 1; }
grep -Eq '/build/assets/[^" ]+\.(js|css)' "$ROOT_HTML" || { echo 'FAIL: SPA root does not reference built Vite assets' >&2; exit 1; }

status="$(request_status GET "$BASE_URL/up" "$TMP_DIR/up")"
expect_status 200 "$status" "liveness /up"
status="$(request_status GET "$BASE_URL/api/health/ready" "$TMP_DIR/ready")"
expect_status 200 "$status" "readiness /api/health/ready"
status="$(request_status GET "$BASE_URL/api/dashboard" "$TMP_DIR/dashboard-unauth" -H 'Accept: application/json')"
expect_status 401 "$status" "unauthenticated dashboard"
status="$(request_status GET "$BASE_URL/api/demo-access" "$TMP_DIR/demo" -H 'Accept: application/json')"
expect_status 404 "$status" "production demo-access disabled"

curl -sSI "${CURL_COMMON[@]}" "$BASE_URL/up" > "$HEADERS"
grep -qi '^X-Content-Type-Options: nosniff' "$HEADERS" || { echo 'FAIL: X-Content-Type-Options missing' >&2; exit 1; }
grep -qi '^X-Frame-Options: DENY' "$HEADERS" || { echo 'FAIL: X-Frame-Options missing' >&2; exit 1; }
grep -qi '^Referrer-Policy: same-origin' "$HEADERS" || { echo 'FAIL: Referrer-Policy missing' >&2; exit 1; }
grep -qi '^Permissions-Policy:' "$HEADERS" || { echo 'FAIL: Permissions-Policy missing' >&2; exit 1; }
grep -qi '^Content-Security-Policy:' "$HEADERS" || { echo 'FAIL: Content-Security-Policy missing' >&2; exit 1; }
if [[ "$FORWARDED_PROTO" == "https" ]]; then
  grep -qi '^Strict-Transport-Security: max-age=31536000; includeSubDomains' "$HEADERS" || { echo 'FAIL: Strict-Transport-Security missing for HTTPS acceptance request' >&2; exit 1; }
  echo 'PASS: HTTPS HSTS header present'
fi
echo 'PASS: required security headers present'

if [[ -z "$EMAIL" || -z "$PASSWORD" ]]; then
  if [[ "$ALLOW_UNAUTH_ONLY" == "true" ]]; then
    echo 'WARNING: authenticated acceptance explicitly disabled; unauthenticated checks only.' >&2
    exit 0
  fi
  echo 'FAIL: NADI_ACCEPTANCE_EMAIL and NADI_ACCEPTANCE_PASSWORD are required for production acceptance.' >&2
  exit 1
fi

printf '\n[NADI acceptance] Authenticated session/RBAC flow\n'
curl -fsS "${CURL_COMMON[@]}" -c "$COOKIE_JAR" "$BASE_URL/" -o "$ROOT_HTML"
CSRF_TOKEN="$(sed -n 's/.*<meta name="csrf-token" content="\([^"]*\)".*/\1/p' "$ROOT_HTML" | head -1)"
if [[ -z "$CSRF_TOKEN" ]]; then
  echo 'FAIL: could not obtain CSRF token from SPA shell' >&2
  exit 1
fi

LOGIN_BODY="$TMP_DIR/login.json"
status="$(curl -sS "${CURL_COMMON[@]}" -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -H "X-CSRF-TOKEN: $CSRF_TOKEN" \
  -o "$LOGIN_BODY" -w '%{http_code}' \
  --data "{\"email\":\"$EMAIL\",\"password\":\"$PASSWORD\"}" \
  "$BASE_URL/api/login")"
expect_status 200 "$status" "administrator login"
grep -q '"user"' "$LOGIN_BODY" || { echo 'FAIL: login response has no user payload' >&2; exit 1; }

READ_ENDPOINTS=(
  '/api/me'
  '/api/dashboard'
  '/api/certification'
  '/api/finance'
  '/api/it'
  '/api/governance'
  '/api/kpi-catalog'
  '/api/decisions'
  '/api/notifications'
  '/api/reports'
  '/api/integrations'
  '/api/master-data'
  '/api/admin/users'
)
for endpoint in "${READ_ENDPOINTS[@]}"; do
  body="$TMP_DIR/$(echo "$endpoint" | tr '/?' '__').json"
  status="$(request_status GET "$BASE_URL$endpoint" "$body" -b "$COOKIE_JAR" -H 'Accept: application/json')"
  expect_status 200 "$status" "authenticated GET $endpoint"
done

LOGOUT_BODY="$TMP_DIR/logout.json"
status="$(curl -sS "${CURL_COMMON[@]}" -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -H "X-CSRF-TOKEN: $CSRF_TOKEN" \
  -o "$LOGOUT_BODY" -w '%{http_code}' \
  -X POST "$BASE_URL/api/logout")"
expect_status 200 "$status" "logout"

status="$(request_status GET "$BASE_URL/api/me" "$TMP_DIR/me-after-logout" -b "$COOKIE_JAR" -H 'Accept: application/json')"
expect_status 401 "$status" "session invalid after logout"

echo 'NADI HTTP acceptance smoke: PASS'
