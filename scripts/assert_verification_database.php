<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = (string) config('database.connections.'.config('database.default').'.database');
$acknowledged = (string) env('NADI_VERIFY_DESTRUCTIVE_DATABASE', '');

if ($database === '' || $acknowledged === '' || ! hash_equals($database, $acknowledged)) {
    fwrite(STDERR, "FAIL: destructive verification requires NADI_VERIFY_DESTRUCTIVE_DATABASE to exactly match DB_DATABASE ({$database}).\n");
    exit(1);
}

$normalized = strtolower($database);
if (! preg_match('/(?:^|[_-])(test|testing|ci|verify|verification)(?:$|[_-])/', $normalized)) {
    fwrite(STDERR, "FAIL: verification database name must be explicitly test/ci/verify scoped; refusing migrate:fresh on {$database}.\n");
    exit(1);
}

$driver = DB::connection()->getDriverName();
if ($driver !== 'mysql') {
    fwrite(STDERR, "FAIL: release verification requires MySQL, got {$driver}.\n");
    exit(1);
}

DB::select('select 1');
echo "PASS destructive verification guard: mysql database={$database}\n";
