<?php

namespace Tests\Feature\RepositoryAnalysis;

use App\CodeChunking\BuildRevisionChunks;
use App\CodeRetrieval\BaselineRetrieval;
use App\CodeRetrieval\KeywordRetrieval;
use App\CodeRetrieval\StructureAwareRetrieval;
use App\Models\CodeChunk;
use App\Models\IndexRun;
use App\Models\Project;
use App\Models\ProjectRevision;
use App\Models\User;
use App\PhpAnalysis\PhpRevisionAnalyzer;
use App\RepositoryScanning\RepositoryInventoryScanner;
use App\RepositoryScanning\ResolvedRepositorySnapshot;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\RefreshDatabase;
use Tests\TestCase;

class CodeRetrievalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('repository_snapshots');
    }

    public function test_baseline_order_is_deterministic_and_question_independent(): void
    {
        $revision = $this->indexed($this->structuralFiles());
        $strategy = app(BaselineRetrieval::class);
        $first = $strategy->retrieve($revision->id, 'fetchInvoice');
        $this->assertCount(10, $first);
        $this->assertSame(['app/Audit.php', 1, 1], [$first[0]['path'], $first[0]['start_line'], $first[0]['end_line']]);
        $this->assertSame($first, $strategy->retrieve($revision->id, 'completely different question'));
        $keys = array_map(fn (array $candidate): array => [$candidate['path'], $candidate['start_line'], $candidate['chunk_id']], $first);
        $sorted = $keys;
        sort($sorted);
        $this->assertSame($sorted, $keys);
        $this->assertSame(range(1, count($first)), array_column($first, 'rank'));
        $this->assertSame(['app/Audit.php', 'app/Loader.php', 'app/Readable.php', 'app/Source.php'], array_values(array_unique(array_column($first, 'path'))));
        foreach ($first as $candidate) {
            $this->assertNull($candidate['score']);
            $this->assertSame($revision->id, $candidate['revision_id']);
            $this->assertSame($revision->identifier, $candidate['revision_identifier']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $candidate['chunk_id']);
        }
    }

    public function test_keyword_normalization_ranking_and_ties(): void
    {
        $keyword = app(KeywordRetrieval::class);
        $this->assertSame(['http', 'invoice', 'load', 'store', 'xml'], $keyword->terms('XMLHttp\\Invoice_store loadInvoice'));
        $revision = $this->indexed([
            'z.php' => '<?php // invoice invoice invoice',
            'a.php' => '<?php // invoice',
            'Invoice_store.php' => '<?php // unrelated',
            'unrelated.php' => '<?php // potato',
        ]);
        $result = $keyword->retrieve($revision->id, 'Invoice');
        $this->assertSame(['Invoice_store.php', 'a.php', 'z.php'], array_column($result, 'path'));
        $this->assertSame([2, 1, 1], array_column($result, 'score'));
        $this->assertSame($result, $keyword->retrieve($revision->id, 'invoice INVOICE'));
        $this->assertSame([], $keyword->retrieve($revision->id, 'unfindableToken'));
        $this->assertSame([], $keyword->retrieve($revision->id, '!!!'));
    }

    public function test_structure_expands_verified_class_and_method_edges_without_duplicates(): void
    {
        $revision = $this->indexed($this->structuralFiles());
        $keyword = app(KeywordRetrieval::class)->retrieve($revision->id, 'fetchInvoice');
        $this->assertCount(1, $keyword);
        $this->assertSame(['app/Loader.php', 5, 8, 8], [$keyword[0]['path'], $keyword[0]['start_line'], $keyword[0]['end_line'], $keyword[0]['score']]);
        $strategy = app(StructureAwareRetrieval::class);
        $result = $strategy->retrieve($revision->id, 'fetchInvoice');
        $this->assertSame([
            ['app/Loader.php', 5, 8], ['app/Loader.php', 2, 4], ['app/Loader.php', 9, 9],
            ['app/Audit.php', 2, 2], ['app/Readable.php', 2, 2], ['app/Source.php', 2, 2],
        ], array_map(fn (array $candidate): array => [$candidate['path'], $candidate['start_line'], $candidate['end_line']], $result));
        $this->assertSame($result, $strategy->retrieve($revision->id, 'fetchInvoice'));
        $reasons = implode('\n', array_column($result, 'reason'));
        foreach (['class_method', 'extends', 'implements', 'trait_use'] as $type) {
            $this->assertStringContainsString($type, $reasons);
        }
        $this->assertSame($keyword[0]['chunk_id'], $result[0]['chunk_id']);
        $this->assertSame(count($result), count(array_unique(array_column($result, 'chunk_id'))));
        $this->assertContains('app/Source.php', array_column($result, 'path'));
        $this->assertNotContains('outside.php', array_column($result, 'path'));
        $this->assertNull($result[1]['score']);
        $this->assertSame([], $strategy->retrieve($revision->id, 'missingMarker'));
        DB::table('code_relations')->where('project_revision_id', $revision->id)->where('type', 'extends')->update(['to_symbol_id' => null]);
        $withoutTarget = $strategy->retrieve($revision->id, 'fetchInvoice');
        $this->assertNotContains('app/Source.php', array_column($withoutTarget, 'path'));
    }

    public function test_structure_bounds_depth_and_candidate_count(): void
    {
        $revision = $this->indexed([
            'a.php' => "<?php\nclass A extends B { /* needle */ }",
            'b.php' => "<?php\nclass B extends C {}",
            'c.php' => "<?php\nclass C extends D {}",
            'd.php' => "<?php\nclass D {}",
        ]);
        $strategy = app(StructureAwareRetrieval::class);
        $result = $strategy->retrieve($revision->id, 'needle');
        $this->assertSame(['a.php', 'b.php', 'c.php'], array_column($result, 'path'));
        $this->assertStringContainsString('depth 2', $result[2]['reason']);
        $files = [];
        for ($i = 0; $i < 25; $i++) {
            $files[sprintf('Interface%02d.php', $i)] = '<?php interface I'.$i.' {}';
        }
        $files['hub.php'] = '<?php class Hub implements '.implode(', ', array_map(fn (int $i): string => 'I'.$i, range(0, 24))).' { /* needle */ }';
        $wide = $this->indexed($files);
        $result = $strategy->retrieve($wide->id, 'needle');
        $this->assertCount(1 + StructureAwareRetrieval::MAX_ADDITIONAL, $result);
        $this->assertSame('Interface00.php', $result[1]['path']);
        $this->assertSame('Interface19.php', $result[20]['path']);
        $this->assertSame($result, $strategy->retrieve($wide->id, 'needle'));
    }

    public function test_laravel_routes_and_resolved_model_relations_expand(): void
    {
        $revision = $this->indexed([
            'routes/web.php' => "<?php\nuse Illuminate\\Support\\Facades\\Route;\nuse App\\Http\\Controllers\\BillingController;\nRoute::get('/reports-endpoint', [BillingController::class, 'index']);",
            'app/Http/Controllers/BillingController.php' => "<?php\nnamespace App\\Http\\Controllers;\nclass BillingController\n{\n public function index() {}\n}",
            'app/Models/Invoice.php' => "<?php\nnamespace App\\Models;\nuse Illuminate\\Database\\Eloquent\\Model;\nclass Invoice extends Model\n{\n public function lines() { return \$this->hasMany(Line::class); }\n}",
            'app/Models/Line.php' => "<?php\nnamespace App\\Models;\nuse Illuminate\\Database\\Eloquent\\Model;\nclass Line extends Model {}",
        ]);
        $strategy = app(StructureAwareRetrieval::class);
        $routes = $strategy->retrieve($revision->id, 'reportsEndpoint');
        $this->assertStringContainsString('route_controller', implode('\n', array_column($routes, 'reason')));
        $this->assertContains('app/Http/Controllers/BillingController.php', array_column($routes, 'path'));
        $models = $strategy->retrieve($revision->id, 'invoice');
        $this->assertStringContainsString('model:hasMany', implode('\n', array_column($models, 'reason')));
        $this->assertContains('app/Models/Line.php', array_column($models, 'path'));
        DB::table('laravel_model_relations')->where('project_revision_id', $revision->id)->update(['related_laravel_model_id' => null]);
        $this->assertNotContains('app/Models/Line.php', array_column($strategy->retrieve($revision->id, 'invoice'), 'path'));
    }

    public function test_structure_uses_only_the_first_five_lexical_seeds(): void
    {
        $files = [];
        for ($i = 0; $i < 9; $i++) {
            $files[$i.'.php'] = '<?php // needle';
        }
        $revision = $this->indexed($files);
        $keyword = app(KeywordRetrieval::class)->retrieve($revision->id, 'needle');
        $structure = app(StructureAwareRetrieval::class)->retrieve($revision->id, 'needle');
        $this->assertCount(9, $keyword);
        $this->assertSame(array_slice(array_column($keyword, 'chunk_id'), 0, 5), array_column($structure, 'chunk_id'));
    }

    public function test_all_methods_are_revision_isolated_and_command_outputs_json(): void
    {
        $first = $this->indexed($this->structuralFiles());
        $other = $this->indexed(['outside.php' => '<?php // fetchInvoice'], $first->project);
        foreach (['baseline' => BaselineRetrieval::class, 'keyword' => KeywordRetrieval::class, 'structure_aware' => StructureAwareRetrieval::class] as $method => $class) {
            $strategy = app($class);
            $result = $strategy->retrieve($first->id, 'How does fetchInvoice work?');
            $this->assertNotContains('outside.php', array_column($result, 'path'));
            $this->assertSame([$first->id], array_values(array_unique(array_column($result, 'revision_id'))));
            $this->assertSame(['outside.php'], array_column($strategy->retrieve($other->id, 'fetchInvoice'), 'path'));
            $this->assertSame(0, Artisan::call('thesis:retrieve', ['revisionId' => $first->id, 'question' => 'How does fetchInvoice work?', '--method' => $method]));
            $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($method, $json['method']);
            $this->assertSame($result, $json['candidates']);
        }
    }

    public function test_fallback_identifiers_ties_and_duplicate_elimination_do_not_use_database_ids(): void
    {
        $revision = $this->indexed(['a.php' => '<?php // invoice']);
        $original = CodeChunk::query()->where('project_revision_id', $revision->id)->sole();
        $original->update(['metadata' => null]);
        $baseline = app(BaselineRetrieval::class);
        $before = $baseline->retrieve($revision->id, 'invoice');
        $copy = $original->replicate();
        $copy->forceFill(['chunk_index' => 1])->save();
        $this->assertSame($before, $baseline->retrieve($revision->id, 'anything'));
        $original->delete();
        $this->assertSame($before, $baseline->retrieve($revision->id, 'anything'));
        $copy->update(['metadata' => ['stable_id' => str_repeat('b', 64)]]);
        $third = $copy->replicate();
        $third->forceFill(['chunk_index' => 2, 'metadata' => ['stable_id' => str_repeat('a', 64)]])->save();
        $this->assertSame([str_repeat('a', 64), str_repeat('b', 64)], array_column($baseline->retrieve($revision->id, 'invoice'), 'chunk_id'));
        $this->assertSame([str_repeat('a', 64), str_repeat('b', 64)], array_column(app(KeywordRetrieval::class)->retrieve($revision->id, 'invoice'), 'chunk_id'));
    }

    public function test_invalid_inputs_fail_explicitly_without_creating_data(): void
    {
        foreach ([['revisionId' => 'invalid', 'question' => 'invoice'], ['revisionId' => 0, 'question' => 'invoice'],
            ['revisionId' => 2147483647, 'question' => 'invoice'], ['revisionId' => 1, 'question' => '   '],
            ['revisionId' => 1, 'question' => 'invoice', '--method' => 'unknown']] as $arguments) {
            $this->assertSame(1, Artisan::call('thesis:retrieve', $arguments));
            $this->assertArrayHasKey('error', json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR));
        }
        $revision = $this->indexed(['a.php' => '<?php echo 1;']);
        CodeChunk::query()->where('project_revision_id', $revision->id)->delete();
        foreach (['baseline', 'keyword', 'structure_aware'] as $method) {
            $this->assertSame(1, Artisan::call('thesis:retrieve', ['revisionId' => $revision->id, 'question' => 'invoice', '--method' => $method]));
            $this->assertStringContainsString('no generated chunks', Artisan::output());
        }
        $this->assertDatabaseCount('code_chunks', 0);
    }

    /** @return array<string, string> */
    private function structuralFiles(): array
    {
        return [
            'app/Loader.php' => "<?php\nclass Loader extends Source implements Readable\n{\n use Audit;\n public function fetchInvoice()\n {\n  return 42;\n }\n}",
            'app/Source.php' => "<?php\nclass Source implements Readable {}",
            'app/Readable.php' => "<?php\ninterface Readable {}",
            'app/Audit.php' => "<?php\ntrait Audit {}",
        ];
    }

    /**
     * @param  array<string, string>  $files
     */
    private function indexed(array $files, ?Project $project = null): ProjectRevision
    {
        $project ??= User::factory()->create()->projects()->create(['name' => 'Retrieval fixture', 'slug' => 'retrieval-fixture']);
        $source = $project->sources()->firstOrCreate(['name' => 'Fixture'], ['type' => 'local']);
        $revision = new ProjectRevision(['identifier' => 'fixture-'.$source->revisions()->count()]);
        $revision->project()->associate($project);
        $source->revisions()->save($revision);
        $path = 'projects/'.$project->id.'/revisions/'.$revision->id;
        $disk = Storage::disk('repository_snapshots');
        foreach ($files as $name => $content) {
            $disk->put($path.'/'.$name, $content);
        }
        $revision->update(['metadata' => ['snapshot' => ['disk' => 'repository_snapshots', 'path' => $path]]]);
        $root = $disk->path($path);
        app(RepositoryInventoryScanner::class)->scan(new ResolvedRepositorySnapshot($revision, $root, 'fixture', false, []));
        $analysis = app(PhpRevisionAnalyzer::class)->analyze($revision, $root);
        $run = new IndexRun(['status' => 'completed', 'trigger' => 'manual', 'statistics' => ['php_files_analyzed' => $analysis->filesAnalyzed]]);
        $run->project()->associate($project);
        $revision->indexRuns()->save($run);
        app(BuildRevisionChunks::class)->handle($revision->id);

        return $revision;
    }
}
