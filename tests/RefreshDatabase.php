<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase as LaravelRefreshDatabase;

trait RefreshDatabase
{
    use LaravelRefreshDatabase;

    protected function migrateDatabases(): void
    {
        // Keep existing tables and records; Laravel rolls back each test's transaction.
        $this->artisan('migrate')->assertExitCode(0);
    }
}
