<?php

namespace Tests\Feature\RepositoryAnalysis;

use App\Models\AnalysisIssue;
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

    public function test_only_direct_forward_operations_are_persisted_once(): void
    {
        $revision = $this->regressionRevision();
        $analyzer = app(PhpRevisionAnalyzer::class);
        $first = $analyzer->analyze($revision, base_path('tests/Fixtures/LaravelMigrationRegression'));
        $second = $analyzer->analyze($revision, base_path('tests/Fixtures/LaravelMigrationRegression'));

        $this->assertEquals($first, $second);
        $this->assertSame(['entries'], DB::table('database_tables')->pluck('name')->all());
        $this->assertDatabaseCount('database_columns', 10);
        $this->assertDatabaseCount('database_indexes', 2);
        $this->assertDatabaseMissing('database_columns', ['name' => 'rollback_only']);
        $this->assertDatabaseHas('database_columns', ['name' => 'title', 'is_nullable' => true]);
        $this->assertSame(4, $second->migrationIssuesPersisted);
        $this->assertSame(4, DB::table('analysis_issues')->where('category', 'laravel_migration')->count());
    }

    public function test_constrained_uses_laravel_names_and_explicit_arguments_without_guessing_dynamic_values(): void
    {
        $revision = $this->regressionRevision();
        app(PhpRevisionAnalyzer::class)->analyze($revision, base_path('tests/Fixtures/LaravelMigrationRegression'));

        $this->assertSame([
            'entries_grid_id_foreign' => 'grids',
            'entries_category_id_foreign' => 'categories',
            'entries_person_id_foreign' => 'people',
            'entries_owner_id_foreign' => 'accounts',
            'entries_editor_id_foreign' => 'users',
            'entry_account_fk' => 'accounts',
        ], DB::table('database_foreign_keys')->orderBy('id')->pluck('referenced_table_name', 'name')->all());
        $key = DB::table('database_foreign_keys')->where('name', 'entry_account_fk')->sole();
        $this->assertDatabaseHas('database_foreign_key_columns', [
            'database_foreign_key_id' => $key->id,
            'referenced_column_name' => 'key',
        ]);
        $this->assertDatabaseMissing('database_foreign_keys', ['referenced_table_name' => 'grs']);
    }

    private function regressionRevision(): ProjectRevision
    {
        $project = User::factory()->create()->projects()->create(['name' => 'Migration regression', 'slug' => 'migration-regression']);
        $source = $project->sources()->create(['type' => 'local', 'name' => 'Fixtures']);
        $revision = new ProjectRevision(['identifier' => 'fixture']);
        $revision->project()->associate($project);
        $source->revisions()->save($revision);
        $path = 'database/migrations/2026_01_01_000000_create_entries.php';
        $contents = file_get_contents(base_path('tests/Fixtures/LaravelMigrationRegression/'.$path));
        CodeFile::forceCreate(['project_id' => $project->id, 'project_revision_id' => $revision->id, 'path' => $path, 'language' => 'PHP', 'content_hash' => hash('sha256', $contents), 'size_bytes' => strlen($contents), 'line_count' => substr_count($contents, "\n")]);

        return $revision;
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
        $unrelated = AnalysisIssue::forceCreate([
            'project_id' => $project->id, 'project_revision_id' => $revision->id,
            'severity' => 'info', 'category' => 'repository_scan', 'code' => 'scan.preserved', 'title' => 'Preserve scan diagnostic',
        ]);
        $otherRevision = new ProjectRevision(['identifier' => 'other']);
        $otherRevision->project()->associate($project);
        $source->revisions()->save($otherRevision);
        $otherIssue = AnalysisIssue::forceCreate([
            'project_id' => $project->id, 'project_revision_id' => $otherRevision->id,
            'severity' => 'info', 'category' => 'laravel_migration', 'code' => 'laravel_migration.unsupported', 'title' => 'Other revision',
        ]);
        $analyzer = app(PhpRevisionAnalyzer::class);
        $first = $analyzer->analyze($revision, base_path('tests/Fixtures/LaravelDynamicMigration'));
        $second = $analyzer->analyze($revision, base_path('tests/Fixtures/LaravelDynamicMigration'));
        $this->assertEquals($first, $second);
        $this->assertSame(1, $second->migrationIssuesPersisted);
        $this->assertSame(1, $second->issuesPersisted);
        $this->assertSame(1, DB::table('analysis_issues')->where('project_revision_id', $revision->id)->where('category', 'laravel_migration')->count());
        $this->assertDatabaseHas('analysis_issues', ['id' => $unrelated->id, 'category' => 'repository_scan']);
        $this->assertDatabaseHas('analysis_issues', ['id' => $otherIssue->id, 'project_revision_id' => $otherRevision->id]);
        $this->assertDatabaseHas('analysis_issues', ['project_revision_id' => $revision->id, 'category' => 'laravel_migration', 'code' => 'laravel_migration.unsupported']);
        $this->assertDatabaseCount('database_tables', 0);
    }
}
