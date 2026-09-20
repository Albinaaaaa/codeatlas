<?php

namespace Tests\Unit;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DatabaseSafetyTest extends TestCase
{
    /** @return array<string, array{bool, string, string, bool}> */
    public static function databaseSettings(): array
    {
        return [
            'working environment' => [false, 'codeatlas_test', 'codeatlas_test', false],
            'working database' => [true, 'codeatlas', 'codeatlas', false],
            'connection points elsewhere' => [true, 'codeatlas_test', 'codeatlas', false],
            'isolated test database' => [true, 'codeatlas_test', 'codeatlas_test', true],
        ];
    }

    #[DataProvider('databaseSettings')]
    public function test_database_guard(bool $testing, string $configured, string $connected, bool $allowed): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDriverName')->willReturn('pgsql');
        $connection->method('getDatabaseName')->willReturn($configured);
        $connection->method('selectOne')->willReturn((object) ['name' => $connected]);
        $connection->expects($this->never())->method('statement');
        $database = $this->createStub(DatabaseManager::class);
        $database->method('connection')->willReturn($connection);
        $app = $this->createStub(Application::class);
        $app->method('environment')->willReturn($testing);
        $app->method('make')->willReturn($database);

        $test = new class('unused') extends \Tests\TestCase
        {
            public function verifyDatabase(Application $app): void
            {
                $this->assertSafeTestDatabase($app);
            }
        };

        if (! $allowed) {
            $this->expectException(RuntimeException::class);
        }

        $test->verifyDatabase($app);
    }
}
