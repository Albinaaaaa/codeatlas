<?php

namespace Tests\Feature\RepositoryAnalysis;

use App\Models\AnalysisIssue;
use App\Models\CodeFile;
use App\Models\CodeRelation;
use App\Models\CodeSymbol;
use App\Models\LaravelModel;
use App\Models\LaravelModelRelation;
use App\Models\Project;
use App\Models\ProjectRevision;
use App\Models\User;
use App\PhpAnalysis\PhpRevisionAnalyzer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\RefreshDatabase;
use Tests\TestCase;

class LaravelModelAnalyzerTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_configuration_and_all_relationship_types_are_extracted_without_execution(): void
    {
        $revision = $this->revision();
        $result = app(PhpRevisionAnalyzer::class)->analyze($revision, base_path('tests/Fixtures/LaravelModels'));

        $album = $this->model($revision, 'Album');
        $metadata = json_decode($album->metadata, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('music_albums', $album->table_name);
        $this->assertSame('archive', $album->connection);
        $this->assertSame('uuid', $metadata['configuration']['primaryKey']);
        $this->assertSame('string', $metadata['configuration']['keyType']);
        $this->assertFalse($metadata['configuration']['incrementing']);
        $this->assertSame([], $metadata['configuration']['guarded']);
        $this->assertSame(['title', 'released_at'], $metadata['configuration']['fillable']);
        $this->assertEquals([
            'title' => 'string', 'active' => 'boolean', 'released_at' => 'datetime', 'state' => 'Fixture\State',
        ], $metadata['configuration']['casts']);
        $this->assertSame('Models.php', $metadata['source_path']);
        $this->assertGreaterThan(0, $metadata['start_line']);
        $this->assertGreaterThan($metadata['start_line'], $metadata['end_line']);
        $this->assertSame('news_items', $this->model($revision, 'NewsItem')->table_name);
        $this->assertNull($this->model($revision, 'DynamicTable')->table_name);
        $this->assertSame('child_albums', $this->model($revision, 'ChildAlbum')->table_name);

        $relations = DB::table('laravel_model_relations')->where('laravel_model_id', $album->id)->get()->keyBy('name');
        $this->assertCount(14, $relations);
        $this->assertEqualsCanonicalizing([
            'hasOne', 'hasMany', 'belongsTo', 'belongsToMany', 'hasOneThrough', 'hasManyThrough',
            'morphOne', 'morphMany', 'morphTo', 'morphToMany', 'morphedByMany',
        ], $relations->pluck('relation_type')->unique()->values()->all());
        $this->assertSame('Fixture\Models\Track', $relations['tracks']->related_model);
        $this->assertSame($this->model($revision, 'Track')->id, $relations['tracks']->related_laravel_model_id);
        $this->assertSame('album_uuid', $relations['tracks']->foreign_key);
        $this->assertSame('uuid', $relations['tracks']->local_key);
        $this->assertSame('owner_uuid', $relations['owner']->foreign_key);
        $this->assertSame('uuid', json_decode($relations['owner']->metadata, true)['arguments']['ownerKey']);
        $this->assertSame('album_user', $relations['users']->pivot_table);
        $this->assertSame('*', $relations['subject']->related_model);
        $this->assertNull($relations['subject']->related_laravel_model_id);
        $this->assertNull($relations['external']->related_laravel_model_id);
        $this->assertSame($album->id, $relations['parentAlbum']->related_laravel_model_id);
        $inherited = json_decode($relations['inherited']->metadata, true);
        $this->assertSame('BaseRecord.php', $inherited['source_path']);
        $this->assertDatabaseHas('code_symbols', [
            'id' => $inherited['method_symbol_id'],
            'qualified_name' => 'Fixture\Models\BaseRecord::inherited',
        ]);
        $this->assertDatabaseHas('laravel_model_relations', [
            'laravel_model_id' => $this->model($revision, 'ChildAlbum')->id,
            'name' => 'tracks', 'relation_type' => 'hasOne',
        ]);
        $this->assertGreaterThan(0, $result->modelsPersisted);
        $this->assertSame(0, DB::table('laravel_model_relations')->whereNull('start_line')->orWhereNull('end_line')->count());
    }

    public function test_dynamic_and_unsupported_definitions_are_not_guessed(): void
    {
        $revision = $this->revision();
        app(PhpRevisionAnalyzer::class)->analyze($revision, base_path('tests/Fixtures/LaravelModels'));

        foreach (['Unsupported', 'Overridden', 'Traited'] as $name) {
            $this->assertSame(0, DB::table('laravel_model_relations')->where('laravel_model_id', $this->model($revision, $name)->id)->count());
        }
        foreach (['NotEloquent', 'UnknownBase', 'CycleA', 'CycleB', 'ConditionalModel'] as $name) {
            $this->assertDatabaseMissing('laravel_models', ['metadata->class' => 'Fixture\Models\\'.$name]);
        }
        $this->assertGreaterThanOrEqual(11, DB::table('analysis_issues')->where('category', 'laravel_model')->count());
        $this->assertDatabaseHas('analysis_issues', [
            'project_id' => $revision->project_id, 'project_revision_id' => $revision->id,
            'source_path' => 'Unsupported.php', 'code' => 'laravel_model.unsupported_definition',
        ]);
    }

    public function test_reanalysis_is_idempotent_and_does_not_change_other_revisions_or_foreign_keys(): void
    {
        $revision = $this->revision();
        $other = $this->revision();
        $analyzer = app(PhpRevisionAnalyzer::class);
        $root = base_path('tests/Fixtures/LaravelModels');
        $analyzer->analyze($other, $root);
        $otherModel = $this->model($other, 'Album');
        $foreignKeyTables = array_values(array_filter(
            Schema::getTableListing(),
            fn (string $table): bool => str_contains($table, 'foreign_keys'),
        ));
        $foreignKeyCounts = [];
        foreach ($foreignKeyTables as $table) {
            $foreignKeyCounts[$table] = DB::table($table)->count();
        }

        $first = $analyzer->analyze($revision, $root);
        $second = $analyzer->analyze($revision, $root);

        $this->assertEquals($first, $second);
        $this->assertSame($otherModel->id, $this->model($other, 'Album')->id);
        $this->assertSame($first->modelsPersisted, DB::table('laravel_models')->where('project_revision_id', $revision->id)->count());
        $this->assertSame($first->modelRelationsPersisted, DB::table('laravel_model_relations')->where('project_revision_id', $revision->id)->count());
        $this->assertSame($first->issuesPersisted, DB::table('analysis_issues')->where('project_revision_id', $revision->id)->count());
        foreach ($foreignKeyCounts as $table => $count) {
            $this->assertSame($count, DB::table($table)->count());
        }
        $this->assertSame(0, DB::table('laravel_model_relations as relations')
            ->join('laravel_models as models', 'models.id', '=', 'relations.related_laravel_model_id')
            ->whereColumn('relations.project_revision_id', '!=', 'models.project_revision_id')->count());
    }

    public function test_models_ui_is_authorized_and_uses_latest_project_revision(): void
    {
        $revision = $this->revision();
        app(PhpRevisionAnalyzer::class)->analyze($revision, base_path('tests/Fixtures/LaravelModels'));
        $project = $revision->project;
        $this->actingAs(User::findOrFail($project->user_id))->get(route('projects.show', $project))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('projects/show')
            ->has('models', 10)
            ->where('models.0.class', 'Fixture\Models\Album')
            ->where('models.0.table_name', 'music_albums')
            ->has('models.0.relations', 14));
        $this->actingAs(User::factory()->create())->get(route('projects.show', $project))->assertForbidden();

        $this->revision($project, 'newer-empty', false);
        $this->actingAs(User::findOrFail($project->user_id))->get(route('projects.show', $project))
            ->assertInertia(fn (Assert $page) => $page->has('models', 0));
    }

    public function test_analysis_uses_eloquent_events_casts_and_relationships(): void
    {
        $revision = $this->revision();
        $classes = [CodeSymbol::class, CodeRelation::class, LaravelModel::class, LaravelModelRelation::class, AnalysisIssue::class];
        Event::fake(array_map(fn (string $class): string => 'eloquent.created: '.$class, $classes));

        $result = app(PhpRevisionAnalyzer::class)->analyze($revision, base_path('tests/Fixtures/LaravelModels'));

        foreach ([
            CodeSymbol::class => $result->symbolsPersisted,
            CodeRelation::class => $result->relationsPersisted,
            LaravelModel::class => $result->modelsPersisted,
            LaravelModelRelation::class => $result->modelRelationsPersisted,
            AnalysisIssue::class => $result->issuesPersisted,
        ] as $class => $count) {
            Event::assertDispatchedTimes('eloquent.created: '.$class, $count);
        }

        $album = LaravelModel::query()->where('table_name', 'music_albums')->firstOrFail();
        $this->assertIsArray($album->metadata);
        $this->assertSame([], $album->traits);
        $this->assertSame('uuid', $album->metadata['configuration']['primaryKey']);
        $this->assertSame('Fixture\Models\Album', $album->codeSymbol->qualified_name);
        $this->assertTrue($album->revision->is($revision));
        $this->assertNotNull($album->created_at);
        $this->assertNotNull($album->updated_at);

        $relation = LaravelModelRelation::query()->where('laravel_model_id', $album->id)->where('name', 'tracks')->firstOrFail();
        $this->assertSame('album_uuid', $relation->metadata['arguments']['foreignKey']);
        $this->assertTrue($relation->model->is($album));
        $this->assertSame('Fixture\Models\Track', $relation->relatedModel->codeSymbol->qualified_name);
        $this->assertSame('Models.php', $relation->codeFile->path);
        $issue = AnalysisIssue::query()->where('category', 'laravel_model')->firstOrFail();
        $this->assertTrue($issue->revision->is($revision));
        $this->assertNotNull($issue->created_at);
    }

    public function test_model_event_failure_rolls_back_revision_replacement(): void
    {
        $revision = $this->revision();
        $analyzer = app(PhpRevisionAnalyzer::class);
        $root = base_path('tests/Fixtures/LaravelModels');
        $analyzer->analyze($revision, $root);
        $album = $this->model($revision, 'Album');
        $relationIds = LaravelModelRelation::query()->orderBy('id')->pluck('id')->all();
        Event::listen('eloquent.creating: '.LaravelModelRelation::class, function (): void {
            throw new LogicException('Persistence listener failed.');
        });

        try {
            $analyzer->analyze($revision, $root);
            $this->fail('Expected the persistence failure to propagate.');
        } catch (LogicException $exception) {
            $this->assertSame('Persistence listener failed.', $exception->getMessage());
        }

        $this->assertSame($album->id, $this->model($revision, 'Album')->id);
        $this->assertSame($relationIds, LaravelModelRelation::query()->orderBy('id')->pluck('id')->all());
    }

    private function model(ProjectRevision $revision, string $name): object
    {
        return DB::table('laravel_models as models')
            ->join('code_symbols as symbols', 'symbols.id', '=', 'models.code_symbol_id')
            ->where('models.project_revision_id', $revision->id)
            ->where('symbols.qualified_name', 'Fixture\Models\\'.$name)
            ->select('models.*')->firstOrFail();
    }

    private function revision(?Project $project = null, string $identifier = 'fixture', bool $files = true): ProjectRevision
    {
        $project ??= User::factory()->create()->projects()->create(['name' => 'Models', 'slug' => 'models']);
        $source = $project->sources()->first() ?? $project->sources()->create(['type' => 'local', 'name' => 'Fixtures']);
        $revision = new ProjectRevision(['identifier' => $identifier]);
        $revision->project()->associate($project);
        $source->revisions()->save($revision);
        if ($files) {
            foreach (['BaseRecord.php', 'Models.php', 'Unsupported.php'] as $path) {
                $contents = (string) file_get_contents(base_path('tests/Fixtures/LaravelModels/'.$path));
                CodeFile::forceCreate([
                    'project_id' => $project->id, 'project_revision_id' => $revision->id,
                    'path' => $path, 'language' => 'PHP', 'content_hash' => hash('sha256', $contents),
                    'size_bytes' => strlen($contents), 'line_count' => substr_count($contents, "\n"),
                ]);
            }
        }

        return $revision;
    }
}
