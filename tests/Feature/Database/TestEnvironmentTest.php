<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TestEnvironmentTest extends TestCase
{
    public function test_inherited_environment_cannot_select_working_services(): void
    {
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('codeatlas_test', DB::selectOne('select current_database() as name')->name);
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('array', config('session.driver'));
        $this->assertSame('sync', config('queue.default'));
        $this->assertSame('array', config('mail.default'));
    }
}
