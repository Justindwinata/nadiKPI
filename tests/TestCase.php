<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $required = trim((string) env('NADI_REQUIRE_TEST_DB', ''));
        if ($required === '') {
            return;
        }

        $configured = (string) config('database.default');
        if ($configured !== $required) {
            $this->fail("Release test database mismatch: expected {$required}, configured {$configured}.");
        }

        $driver = $this->app['db']->connection()->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver !== $required) {
            $this->fail("Release test PDO driver mismatch: expected {$required}, got {$driver}.");
        }
    }
}
