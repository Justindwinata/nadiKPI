<?php

declare(strict_types=1);

function fail_intake(string $message): never
{
    fwrite(STDERR, "Deployment intake verification failed: {$message}\n");
    exit(2);
}

function regular_file(string $path, string $label): void
{
    if (!is_file($path) || is_link($path)) {
        fail_intake("{$label} missing/not a regular file: {$path}");
    }
}

function load_object(string $path, string $label): array
{
    regular_file($path, $label);
    $raw = file_get_contents($path);
    if ($raw === false) {
        fail_intake("cannot read {$label}: {$path}");
    }
    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        fail_intake("invalid JSON in {$label}: {$e->getMessage()}");
    }
    if (!is_array($decoded)) {
        fail_intake("{$label} must be a JSON object");
    }
    return $decoded;
}

$receiptArg = $argv[1] ?? getenv('NADI_DEPLOYMENT_INTAKE_RECEIPT') ?: '';
if ($receiptArg === '') {
    fail_intake('NADI_DEPLOYMENT_INTAKE_RECEIPT (or receipt path argument) is required');
}
$receiptPath = realpath($receiptArg);
if ($receiptPath === false) {
    fail_intake("receipt does not exist: {$receiptArg}");
}
$receipt = load_object($receiptPath, 'deployment intake receipt');
if (($receipt['schema'] ?? null) !== 'nadi.production-deployment-intake.v1') {
    fail_intake('unsupported deployment intake schema');
}
if (($receipt['status'] ?? null) !== 'AUTHORIZED_FOR_DEPLOYMENT') {
    fail_intake('deployment intake is not AUTHORIZED_FOR_DEPLOYMENT');
}

$expectedRepo = getenv('NADI_EXPECTED_GITHUB_REPOSITORY') ?: '';
if ($expectedRepo === '') {
    fail_intake('NADI_EXPECTED_GITHUB_REPOSITORY is required');
}
if (($receipt['expected_repository'] ?? null) !== $expectedRepo) {
    fail_intake('expected GitHub repository mismatch');
}
$ci = $receipt['ci'] ?? null;
if (!is_array($ci) || ($ci['repository'] ?? null) !== $expectedRepo) {
    fail_intake('CI repository provenance mismatch');
}
if (($ci['event_name'] ?? null) !== 'workflow_dispatch' || ($ci['ref'] ?? null) !== 'refs/heads/main') {
    fail_intake('deployment intake CI provenance is not workflow_dispatch on main');
}

$releaseManifest = getcwd() . DIRECTORY_SEPARATOR . 'RELEASE_MANIFEST.json';
regular_file($releaseManifest, 'release manifest');
if (!hash_equals((string)($receipt['release_manifest_sha256'] ?? ''), hash_file('sha256', $releaseManifest))) {
    fail_intake('current extracted release manifest does not match authorized deployment intake');
}
$manifest = load_object($releaseManifest, 'release manifest');
if (($manifest['version'] ?? null) !== ($receipt['version'] ?? null)) {
    fail_intake('release version differs from deployment intake');
}
if (($manifest['frontend_build_included'] ?? null) !== true) {
    fail_intake('release manifest does not assert frontend_build_included=true');
}

$intakeRoot = dirname($receiptPath);
$releaseRoot = realpath($intakeRoot . DIRECTORY_SEPARATOR . (string)($receipt['release_root'] ?? ''));
$currentRoot = realpath(getcwd());
if ($releaseRoot === false || $currentRoot === false || $releaseRoot !== $currentRoot) {
    fail_intake('deploy-production.sh must run from the release root authorized by DEPLOYMENT_INTAKE.json');
}

$custodyFiles = $receipt['custody_files'] ?? null;
if (!is_array($custodyFiles) || count($custodyFiles) !== 4) {
    fail_intake('deployment custody file set must contain exactly four files');
}
$seen = [];
foreach ($custodyFiles as $entry) {
    if (!is_array($entry)) {
        fail_intake('invalid custody file entry');
    }
    $rel = (string)($entry['path'] ?? '');
    if ($rel === '' || str_starts_with($rel, '/') || str_contains($rel, '..')) {
        fail_intake("unsafe custody file path: {$rel}");
    }
    if (isset($seen[$rel])) {
        fail_intake("duplicate custody file path: {$rel}");
    }
    $seen[$rel] = true;
    $path = $intakeRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    regular_file($path, "custody file {$rel}");
    if ((int)($entry['bytes'] ?? -1) !== filesize($path)) {
        fail_intake("custody file size mismatch: {$rel}");
    }
    if (!hash_equals((string)($entry['sha256'] ?? ''), hash_file('sha256', $path))) {
        fail_intake("custody file SHA-256 mismatch: {$rel}");
    }
}

$decisionPath = $intakeRoot . DIRECTORY_SEPARATOR . 'custody' . DIRECTORY_SEPARATOR . 'FINAL_RELEASE_DECISION.json';
$consumptionPath = $intakeRoot . DIRECTORY_SEPARATOR . 'custody' . DIRECTORY_SEPARATOR . 'EXTERNAL_CI_CONSUMPTION.json';
$decision = load_object($decisionPath, 'FINAL release decision');
$consumption = load_object($consumptionPath, 'external CI consumption receipt');
if (($decision['schema'] ?? null) !== 'nadi.final-release-decision.v1' || ($decision['decision'] ?? null) !== 'FINAL_PASS') {
    fail_intake('FINAL release decision is not valid FINAL_PASS');
}
if (($consumption['schema'] ?? null) !== 'nadi.external-ci-consumption.v1' || ($consumption['status'] ?? null) !== 'FINAL_PASS') {
    fail_intake('external CI consumption receipt is not FINAL_PASS');
}
if (!hash_equals((string)($receipt['final_release_decision_sha256'] ?? ''), hash_file('sha256', $decisionPath))) {
    fail_intake('FINAL release decision hash mismatch');
}
if (!hash_equals((string)($receipt['external_ci_consumption_sha256'] ?? ''), hash_file('sha256', $consumptionPath))) {
    fail_intake('external CI consumption receipt hash mismatch');
}

$package = $receipt['release_package'] ?? null;
if (!is_array($package)) {
    fail_intake('deployment intake release_package missing');
}
$packageRel = (string)($package['path'] ?? '');
$packagePath = $intakeRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $packageRel);
regular_file($packagePath, 'custodied FINAL release ZIP');
if ((int)($package['bytes'] ?? -1) !== filesize($packagePath)) {
    fail_intake('custodied FINAL release ZIP size mismatch');
}
if (!hash_equals((string)($package['sha256'] ?? ''), hash_file('sha256', $packagePath))) {
    fail_intake('custodied FINAL release ZIP hash mismatch');
}

fwrite(STDOUT, "Deployment intake verification PASS\n");
