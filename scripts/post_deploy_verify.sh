#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

BASE_URL="${NADI_POST_DEPLOY_BASE_URL:-}"
HOST_HEADER="${NADI_POST_DEPLOY_HOST_HEADER:-}"
OUTPUT="${NADI_POST_DEPLOY_EVIDENCE:-artifacts/post-deploy/POST_DEPLOY_VERIFICATION.json}"
INTAKE_RECEIPT="${NADI_DEPLOYMENT_INTAKE_RECEIPT:-}"
EXPECTED_REPOSITORY="${NADI_EXPECTED_GITHUB_REPOSITORY:-}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --base-url) BASE_URL="${2:-}"; shift 2 ;;
    --host-header) HOST_HEADER="${2:-}"; shift 2 ;;
    --output) OUTPUT="${2:-}"; shift 2 ;;
    *) echo "Unknown argument: $1" >&2; exit 2 ;;
  esac
done

[[ "$BASE_URL" =~ ^https://[^/]+(/.*)?$ ]] || { echo "NADI_POST_DEPLOY_BASE_URL/--base-url must be an https:// URL" >&2; exit 2; }
[[ -n "$INTAKE_RECEIPT" ]] || { echo "NADI_DEPLOYMENT_INTAKE_RECEIPT is required" >&2; exit 2; }
[[ -n "$EXPECTED_REPOSITORY" ]] || { echo "NADI_EXPECTED_GITHUB_REPOSITORY is required" >&2; exit 2; }
command -v php >/dev/null || { echo "php is required" >&2; exit 2; }
command -v curl >/dev/null || { echo "curl is required" >&2; exit 2; }

EVIDENCE_DIR="$(dirname "$OUTPUT")"
MANIFEST="$EVIDENCE_DIR/POST_DEPLOY_EVIDENCE_MANIFEST.json"
if [[ -e "$EVIDENCE_DIR" ]]; then
  if [[ ! -d "$EVIDENCE_DIR" || -n "$(find "$EVIDENCE_DIR" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null)" ]]; then
    echo "Post-deploy evidence directory must not already contain evidence: $EVIDENCE_DIR" >&2
    exit 2
  fi
else
  mkdir -p "$EVIDENCE_DIR"
fi

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

php scripts/verify_deployment_intake.php "$INTAKE_RECEIPT" >"$tmp/intake.log" 2>&1
php artisan nadi:release-check --production >"$tmp/release-check.log" 2>&1
php artisan schedule:list >"$tmp/schedule.log" 2>&1
grep -q 'nadi:monitor-risks' "$tmp/schedule.log" || { echo "Scheduled nadi:monitor-risks command not found" >&2; exit 3; }

curl_args=(--silent --show-error --fail-with-body --connect-timeout 10 --max-time 30 --dump-header "$tmp/headers.txt" --output "$tmp/body.txt")
if [[ -n "$HOST_HEADER" ]]; then curl_args+=(--header "Host: $HOST_HEADER"); fi

probe() {
  local name="$1" path="$2" expected="$3"
  : >"$tmp/headers.txt"
  local code
  code="$(curl "${curl_args[@]}" --write-out '%{http_code}' "$BASE_URL$path")" || {
    echo "Post-deploy probe failed: $name" >&2
    return 1
  }
  [[ "$code" == "$expected" ]] || { echo "Post-deploy probe $name expected HTTP $expected, got $code" >&2; return 1; }
  cp "$tmp/headers.txt" "$tmp/${name}.headers"
  printf '%s' "$code" >"$tmp/${name}.status"
}

probe up /up 200
probe ready /api/health/ready 200
probe spa / 200

grep -qi '^strict-transport-security:' "$tmp/spa.headers" || { echo "HSTS header missing after production deployment" >&2; exit 3; }
grep -qi '^content-security-policy:' "$tmp/spa.headers" || { echo "CSP header missing after production deployment" >&2; exit 3; }
grep -qi '^x-content-type-options:[[:space:]]*nosniff' "$tmp/spa.headers" || { echo "X-Content-Type-Options nosniff missing" >&2; exit 3; }

for f in intake.log release-check.log schedule.log up.headers up.status ready.headers ready.status spa.headers spa.status; do
  cp "$tmp/$f" "$EVIDENCE_DIR/$f"
done

php -r '
$receiptPath=$argv[1]; $output=$argv[2]; $base=$argv[3]; $host=$argv[4]; $evidenceDir=$argv[5];
$receipt=json_decode(file_get_contents($receiptPath), true, 512, JSON_THROW_ON_ERROR);
$manifest=json_decode(file_get_contents("RELEASE_MANIFEST.json"), true, 512, JSON_THROW_ON_ERROR);
$sha=function($p){ return hash_file("sha256", $p); };
$data=[
 "schema"=>"nadi.post-deploy-verification.v1",
 "status"=>"PASS",
 "verified_at_utc"=>gmdate("c"),
 "base_url"=>$base,
 "host_header"=>$host !== "" ? $host : null,
 "version"=>$manifest["version"] ?? null,
 "release_manifest_sha256"=>$sha("RELEASE_MANIFEST.json"),
 "deployment_intake_sha256"=>$sha($receiptPath),
 "expected_repository"=>$receipt["expected_repository"] ?? null,
 "ci"=>$receipt["ci"] ?? null,
 "checks"=>[
   "deployment_intake"=>"pass",
   "production_release_check"=>"pass",
   "scheduler_nadi_monitor_risks"=>"pass",
   "liveness_http_200"=>"pass",
   "readiness_http_200"=>"pass",
   "spa_http_200"=>"pass",
   "hsts"=>"pass",
   "csp"=>"pass",
   "x_content_type_options_nosniff"=>"pass"
 ],
 "evidence_sha256"=>[
   "intake_log"=>$sha($evidenceDir."/intake.log"),
   "release_check_log"=>$sha($evidenceDir."/release-check.log"),
   "schedule_log"=>$sha($evidenceDir."/schedule.log"),
   "up_headers"=>$sha($evidenceDir."/up.headers"),
   "up_status"=>$sha($evidenceDir."/up.status"),
   "ready_headers"=>$sha($evidenceDir."/ready.headers"),
   "ready_status"=>$sha($evidenceDir."/ready.status"),
   "spa_headers"=>$sha($evidenceDir."/spa.headers"),
   "spa_status"=>$sha($evidenceDir."/spa.status")
 ]
];
file_put_contents($output, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
' "$INTAKE_RECEIPT" "$OUTPUT" "$BASE_URL" "$HOST_HEADER" "$EVIDENCE_DIR"

php -r '
$dir=$argv[1]; $manifestPath=$argv[2]; $output=$argv[3]; $receiptPath=$argv[4];
$receipt=json_decode(file_get_contents($receiptPath), true, 512, JSON_THROW_ON_ERROR);
$files=[];
foreach (scandir($dir) as $name) {
  if ($name === "." || $name === ".." || $name === basename($manifestPath)) continue;
  $path=$dir.DIRECTORY_SEPARATOR.$name;
  if (!is_file($path) || is_link($path)) throw new RuntimeException("Unexpected non-regular evidence entry: ".$name);
  $files[]=["path"=>$name,"sha256"=>hash_file("sha256",$path),"bytes"=>filesize($path)];
}
usort($files, fn($a,$b)=>strcmp($a["path"],$b["path"]));
$data=[
 "schema"=>"nadi.post-deploy-evidence-bundle.v1",
 "status"=>"PASS",
 "created_at_utc"=>gmdate("c"),
 "expected_repository"=>$receipt["expected_repository"] ?? null,
 "ci"=>$receipt["ci"] ?? null,
 "deployment_intake_sha256"=>hash_file("sha256",$receiptPath),
 "post_deploy_verification_sha256"=>hash_file("sha256",$output),
 "files"=>$files
];
file_put_contents($manifestPath, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
' "$EVIDENCE_DIR" "$MANIFEST" "$OUTPUT" "$INTAKE_RECEIPT"

echo "Post-deploy verification PASS: $OUTPUT"
echo "Closed-world post-deploy evidence bundle: $MANIFEST"
