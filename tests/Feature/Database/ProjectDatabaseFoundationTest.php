<?php

namespace Tests\Feature\Database;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\IndexRun;
use App\Models\IndexRunStep;
use App\Models\LocalProjectSource;
use App\Models\Project;
use App\Models\ProjectProfile;
use App\Models\ProjectRevision;
use App\Models\ProjectSetting;
use App\Models\ProjectSource;
use App\Models\ProjectTechnology;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProjectDatabaseFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_project_belongs_to_its_owner(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $project = $this->createProject($owner, 'code-atlas');

        $this->assertTrue($project->owner->is($owner));
        $this->assertTrue($owner->projects->contains($project));
        $this->assertFalse($otherUser->projects->contains($project));
    }

    public function test_core_project_relationships_are_available(): void
    {
        $owner = User::factory()->create();
        $project = $this->createProject($owner, 'relationships');

        $profile = $project->profile()->create([
            'summary' => 'A living architecture map.',
            'primary_language' => 'PHP',
        ]);
        $settings = $project->settings()->create([
            'included_paths' => ['app'],
            'excluded_paths' => ['vendor'],
        ]);
        $technology = $project->technologies()->create([
            'name' => 'Laravel',
            'category' => 'framework',
            'version' => '13',
        ]);
        [$source, $revision] = $this->createRevision($project);
        $localSource = new LocalProjectSource([
            'path' => '/workspace/codeatlas',
        ]);
        $localSource->project()->associate($project);
        $source->local()->save($localSource);

        $run = new IndexRun([
            'status' => 'pending',
        ]);
        $run->project()->associate($project);
        $revision->indexRuns()->save($run);

        $step = new IndexRunStep([
            'name' => 'inventory',
            'status' => 'pending',
        ]);
        $step->project()->associate($project);
        $step->revision()->associate($revision);
        $run->steps()->save($step);

        $conversation = new Conversation([
            'title' => 'Architecture questions',
        ]);
        $conversation->user()->associate($owner);
        $project->conversations()->save($conversation);

        $message = new ConversationMessage([
            'role' => 'user',
            'content' => 'Where are routes defined?',
        ]);
        $message->project()->associate($project);
        $conversation->messages()->save($message);

        $this->assertTrue($project->profile->is($profile));
        $this->assertTrue($project->settings->is($settings));
        $this->assertTrue($project->technologies->contains($technology));
        $this->assertTrue($project->sources->contains($source));
        $this->assertTrue($project->revisions->contains($revision));
        $this->assertTrue($project->indexRuns->contains($run));
        $this->assertTrue($project->conversations->contains($conversation));
        $this->assertTrue($source->local->is($localSource));
        $this->assertTrue($run->steps->contains($step));
        $this->assertTrue($conversation->messages->contains($message));
    }

    public function test_a_revision_cannot_reference_a_source_from_another_project(): void
    {
        $owner = User::factory()->create();
        $firstProject = $this->createProject($owner, 'first-project');
        $secondProject = $this->createProject($owner, 'second-project');
        [$secondSource] = $this->createRevision($secondProject);

        $this->expectException(QueryException::class);

        ProjectRevision::forceCreate([
            'project_id' => $firstProject->id,
            'project_source_id' => $secondSource->id,
            'identifier' => 'cross-project-revision',
        ]);
    }

    public function test_an_index_run_cannot_reference_another_projects_revision(): void
    {
        $owner = User::factory()->create();
        $firstProject = $this->createProject($owner, 'first-index-project');
        $secondProject = $this->createProject($owner, 'second-index-project');
        [, $secondRevision] = $this->createRevision($secondProject);

        $this->expectException(QueryException::class);

        IndexRun::forceCreate([
            'project_id' => $firstProject->id,
            'project_revision_id' => $secondRevision->id,
            'status' => 'pending',
        ]);
    }

    public function test_a_conversation_user_must_own_the_project(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $project = $this->createProject($owner, 'owned-project');

        $this->expectException(QueryException::class);

        Conversation::forceCreate([
            'project_id' => $project->id,
            'user_id' => $otherUser->id,
            'title' => 'Not allowed',
        ]);
    }

    public function test_code_symbol_roles_have_a_composite_primary_key(): void
    {
        $project = $this->createProject(User::factory()->create(), 'symbol-roles');
        [, $revision] = $this->createRevision($project);
        $fileId = DB::table('code_files')->insertGetId([
            'project_id' => $project->id,
            'project_revision_id' => $revision->id,
            'path' => 'app/Models/User.php',
            'content_hash' => hash('sha256', 'user-model'),
        ]);
        $symbolId = DB::table('code_symbols')->insertGetId([
            'project_id' => $project->id,
            'project_revision_id' => $revision->id,
            'code_file_id' => $fileId,
            'kind' => 'class',
            'name' => 'User',
            'qualified_name' => 'App\\Models\\User',
        ]);
        $role = [
            'project_id' => $project->id,
            'project_revision_id' => $revision->id,
            'code_symbol_id' => $symbolId,
            'role' => 'eloquent-model',
        ];

        DB::table('code_symbol_roles')->insert($role);

        $this->expectException(QueryException::class);

        DB::table('code_symbol_roles')->insert($role);
    }

    public function test_ownership_and_project_scope_keys_are_not_mass_assignable(): void
    {
        $protectedKeys = [
            Project::class => ['user_id'],
            ProjectProfile::class => ['project_id'],
            ProjectSetting::class => ['project_id'],
            ProjectTechnology::class => ['project_id'],
            ProjectSource::class => ['project_id'],
            LocalProjectSource::class => ['project_id', 'project_source_id'],
            ProjectRevision::class => ['project_id', 'project_source_id'],
            IndexRun::class => ['project_id', 'project_revision_id'],
            IndexRunStep::class => ['project_id', 'project_revision_id', 'index_run_id'],
            Conversation::class => ['project_id', 'user_id'],
            ConversationMessage::class => ['project_id', 'conversation_id'],
        ];

        foreach ($protectedKeys as $modelClass => $keys) {
            $fillable = (new $modelClass)->getFillable();

            foreach ($keys as $key) {
                $this->assertNotContains($key, $fillable, "$modelClass must guard $key.");
            }
        }
    }

    public function test_composite_physical_foreign_keys_store_ordered_column_pairs(): void
    {
        $project = $this->createProject(User::factory()->create(), 'composite-foreign-keys');
        [, $revision] = $this->createRevision($project);
        $ordersTableId = $this->insertDatabaseTable($project, $revision, 'orders');
        $linesTableId = $this->insertDatabaseTable($project, $revision, 'order_lines');
        $orderTenantId = $this->insertDatabaseColumn($project, $revision, $ordersTableId, 'tenant_id', 1);
        $orderId = $this->insertDatabaseColumn($project, $revision, $ordersTableId, 'id', 2);
        $lineTenantId = $this->insertDatabaseColumn($project, $revision, $linesTableId, 'tenant_id', 1);
        $lineOrderId = $this->insertDatabaseColumn($project, $revision, $linesTableId, 'order_id', 2);
        $foreignKeyId = DB::table('database_foreign_keys')->insertGetId([
            'project_id' => $project->id,
            'project_revision_id' => $revision->id,
            'database_table_id' => $linesTableId,
            'referenced_database_table_id' => $ordersTableId,
            'name' => 'order_lines_order_fk',
            'referenced_schema_name' => 'public',
            'referenced_table_name' => 'orders',
        ]);

        DB::table('database_foreign_key_columns')->insert([
            [
                'project_id' => $project->id,
                'project_revision_id' => $revision->id,
                'database_foreign_key_id' => $foreignKeyId,
                'database_column_id' => $lineTenantId,
                'referenced_database_column_id' => $orderTenantId,
                'ordinal_position' => 1,
                'referenced_column_name' => 'tenant_id',
            ],
            [
                'project_id' => $project->id,
                'project_revision_id' => $revision->id,
                'database_foreign_key_id' => $foreignKeyId,
                'database_column_id' => $lineOrderId,
                'referenced_database_column_id' => $orderId,
                'ordinal_position' => 2,
                'referenced_column_name' => 'id',
            ],
        ]);

        $positions = DB::table('database_foreign_key_columns')
            ->where('database_foreign_key_id', $foreignKeyId)
            ->orderBy('ordinal_position')
            ->pluck('ordinal_position')
            ->all();

        $this->assertSame([1, 2], $positions);
        $this->assertTrue(Schema::hasTable('laravel_model_relations'));
        $this->assertTrue(Schema::hasTable('database_foreign_keys'));
    }

    public function test_hard_deleting_a_project_cascades_through_project_owned_data(): void
    {
        $project = $this->createProject(User::factory()->create(), 'delete-project');
        $project->profile()->create(['summary' => 'Temporary']);
        [, $revision] = $this->createRevision($project);
        $run = new IndexRun([
            'status' => 'complete',
        ]);
        $run->project()->associate($project);
        $revision->indexRuns()->save($run);

        $project->delete();

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
        $this->assertDatabaseMissing('project_profiles', ['project_id' => $project->id]);
        $this->assertDatabaseMissing('project_revisions', ['id' => $revision->id]);
        $this->assertDatabaseMissing('index_runs', ['id' => $run->id]);
    }

    public function test_code_locations_are_available_on_source_backed_records(): void
    {
        $this->assertTrue(Schema::hasColumns('code_symbols', [
            'code_file_id',
            'start_line',
            'end_line',
            'start_column',
            'end_column',
        ]));
        $this->assertTrue(Schema::hasColumns('analysis_issues', [
            'source_path',
            'start_line',
            'end_line',
        ]));
        $this->assertTrue(Schema::hasColumns('message_sources', [
            'source_path',
            'start_line',
            'end_line',
        ]));
    }

    public function test_postgresql_has_vector_support_without_an_approximate_index(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-specific pgvector assertion.');
        }

        $extensionEnabled = (bool) DB::scalar(
            "SELECT EXISTS (SELECT 1 FROM pg_extension WHERE extname = 'vector')",
        );
        $embeddingType = DB::scalar(<<<'SQL'
            SELECT format_type(attribute.atttypid, attribute.atttypmod)
            FROM pg_attribute AS attribute
            JOIN pg_class AS relation ON relation.oid = attribute.attrelid
            WHERE relation.relname = 'code_chunks'
              AND attribute.attname = 'embedding'
              AND attribute.attnum > 0
            SQL
        );
        $approximateIndexCount = (int) DB::scalar(<<<'SQL'
            SELECT count(*)
            FROM pg_indexes
            WHERE tablename = 'code_chunks'
              AND (indexdef ILIKE '%hnsw%' OR indexdef ILIKE '%ivfflat%')
            SQL
        );

        $this->assertTrue($extensionEnabled);
        $this->assertSame('vector(1024)', $embeddingType);
        $this->assertSame(0, $approximateIndexCount);
    }

    private function createProject(User $owner, string $slug): Project
    {
        return $owner->projects()->create([
            'name' => str($slug)->headline()->toString(),
            'slug' => $slug,
        ]);
    }

    /** @return array{ProjectSource, ProjectRevision} */
    private function createRevision(Project $project): array
    {
        $source = $project->sources()->create([
            'type' => 'local',
            'name' => 'source-'.$project->id,
        ]);
        $revision = new ProjectRevision([
            'identifier' => 'revision-'.$project->id,
        ]);
        $revision->project()->associate($project);
        $source->revisions()->save($revision);

        return [$source, $revision];
    }

    private function insertDatabaseTable(Project $project, ProjectRevision $revision, string $name): int
    {
        return DB::table('database_tables')->insertGetId([
            'project_id' => $project->id,
            'project_revision_id' => $revision->id,
            'schema_name' => 'public',
            'name' => $name,
        ]);
    }

    private function insertDatabaseColumn(
        Project $project,
        ProjectRevision $revision,
        int $tableId,
        string $name,
        int $position,
    ): int {
        return DB::table('database_columns')->insertGetId([
            'project_id' => $project->id,
            'project_revision_id' => $revision->id,
            'database_table_id' => $tableId,
            'name' => $name,
            'ordinal_position' => $position,
            'data_type' => 'bigint',
        ]);
    }
}
