<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = DB::connection();
$driver = $connection->getDriverName();
if ($driver !== 'mysql') {
    fwrite(STDERR, "FAIL: expected Laravel database.default=mysql / PDO mysql, got {$driver}.\n");
    exit(1);
}

$pdoDriver = $connection->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
if ($pdoDriver !== 'mysql') {
    fwrite(STDERR, "FAIL: expected PDO driver mysql, got {$pdoDriver}.\n");
    exit(1);
}

$row = $connection->selectOne('SELECT VERSION() AS version');
$version = (string) ($row->version ?? '');
if (stripos($version, 'mariadb') !== false
    || ! preg_match('/^8\.(\d+)/', $version, $matches)) {
    fwrite(STDERR, "FAIL: MySQL 8.x required (MariaDB/other engines are not release targets), server reported {$version}.\n");
    exit(1);
}

echo "PASS MySQL runtime: driver=mysql version={$version}\n";
