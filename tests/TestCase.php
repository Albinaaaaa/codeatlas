<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $this->assertSafeTestDatabase($app);

        return $app;
    }

    protected function assertSafeTestDatabase(Application $app): void
    {
        if (! $app->environment('testing')
            || $app->make('db')->connection()->getDriverName() !== 'pgsql'
            || $app->make('db')->connection()->getDatabaseName() !== 'codeatlas_test') {
            throw new RuntimeException('Tests require the testing environment and the codeatlas_test database. Refusing to run database migrations.');
        }

        $database = $app->make('db')->connection()->selectOne('select current_database() as name');

        if ($database?->name !== 'codeatlas_test') {
            throw new RuntimeException('The connected database is not codeatlas_test. Refusing to run database migrations.');
        }
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
