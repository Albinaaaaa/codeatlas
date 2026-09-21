<?php

namespace Tests\Feature\RepositoryAnalysis;

use App\Models\CodeFile;
use App\Models\ProjectRevision;
use App\Models\User;
use App\PhpAnalysis\PhpRevisionAnalyzer;
use Illuminate\Support\Facades\DB;
use Tests\RefreshDatabase;
use Tests\TestCase;

class LaravelMigrationAnalyzerTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_schema_is_read_from_fixture_without_executing_it(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'Migrations', 'slug' => 'migration-fixture']);
        $source = $project->sources()->create(['type' => 'local', 'name' => 'Fixtures']);
        $revision = new ProjectRevision(['identifier' => 'fixture']);
        $revision->project()->associate($project);
        $source->revisions()->save($revision);
        $path = 'database/migrations/2026_01_01_000000_create_orders.php';
        $contents = file_get_contents(base_path('tests/Fixtures/LaravelMigrations/'.$path));
        CodeFile::forceCreate(['project_id' => $project->id, 'project_revision_id' => $revision->id, 'path' => $path, 'language' => 'PHP', 'content_hash' => hash('sha256', $contents), 'size_bytes' => strlen($contents), 'line_count' => substr_count($contents, "\n")]);

        $result = app(PhpRevisionAnalyzer::class)->analyze($revision, base_path('tests/Fixtures/LaravelMigrations'));

        $table = DB::table('database_tables')->where('project_revision_id', $revision->id)->where('name', 'orders')->first();
        $this->assertNotNull($table);
        $this->assertSame(5, DB::table('database_columns')->where('database_table_id', $table->id)->count());
        $this->assertSame(4, DB::table('database_indexes')->where('database_table_id', $table->id)->count());
        $foreignKey = DB::table('database_foreign_keys')->where('database_table_id', $table->id)->first();
        $this->assertSame('tenants', $foreignKey->referenced_table_name);
        $this->assertSame(2, DB::table('database_foreign_key_columns')->where('database_foreign_key_id', $foreignKey->id)->count());
        $this->assertSame(1, $result->databaseTablesPersisted);
        $this->assertSame($path, json_decode($table->metadata, true)['source_path']);
    }

    public function test_dynamic_migration_constructs_create_diagnostics(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'Dynamic migrations', 'slug' => 'dynamic-migration-fixture']);
        $source = $project->sources()->create(['type' => 'local', 'name' => 'Fixtures']);
        $revision = new ProjectRevision(['identifier' => 'fixture']);
        $revision->project()->associate($project);
        $source->revisions()->save($revision);
        $contents = file_get_contents(base_path('tests/Fixtures/LaravelDynamicMigration/database/migrations/dynamic.php'));
        CodeFile::forceCreate(['project_id' => $project->id, 'project_revision_id' => $revision->id, 'path' => 'database/migrations/dynamic.php', 'language' => 'PHP', 'content_hash' => hash('sha256', $contents), 'size_bytes' => strlen($contents), 'line_count' => 1]);
        app(PhpRevisionAnalyzer::class)->analyze($revision, base_path('tests/Fixtures/LaravelDynamicMigration'));
        $this->assertDatabaseHas('analysis_issues', ['project_revision_id' => $revision->id, 'category' => 'laravel_migration', 'code' => 'laravel_migration.unsupported']);
        $this->assertDatabaseCount('database_tables', 0);
    }
}
