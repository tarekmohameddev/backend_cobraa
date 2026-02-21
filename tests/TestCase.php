<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        if (!app()->environment('testing')) {
            return;
        }

        $connection = (string) config('database.default');
        $driver = (string) config("database.connections.$connection.driver");
        $database = (string) config("database.connections.$connection.database");

        // Guardrail: never allow tests to run against a non-test MySQL database.
        if ($driver !== 'sqlite' && stripos($database, 'test') === false) {
            throw new RuntimeException(
                "Refusing to run tests against non-test database [$database] (driver: $driver). " .
                "Point phpunit.xml DB_DATABASE to a dedicated testing database (e.g. cupra2_test), " .
                "or switch to sqlite :memory: for tests."
            );
        }
    }
}
