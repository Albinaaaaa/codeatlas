<?php

namespace Tests\Feature\RepositoryAnalysis;

use App\CodeChunking\BuildRevisionChunks;
use App\CodeChunking\PhpCodeChunker;
use App\Models\CodeChunk;
use App\Models\IndexRun;
use App\Models\Project;
use App\Models\ProjectRevision;
use App\Models\User;
use App\PhpAnalysis\PhpRevisionAnalyzer;
use App\RepositoryScanning\RepositoryInventoryScanner;
use App\RepositoryScanning\ResolvedRepositorySnapshot;
use Illuminate\Support\Facades\Storage;
use Tests\RefreshDatabase;
use Tests\TestCase;

class CodeChunkingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('repository_snapshots');
    }

    public function test_chunks_cover_class_methods_functions_and_file_code_exactly_once(): void
    {
        $content = (string) file_get_contents(base_path('tests/Fixtures/CodeChunking/Example.php'));
        [$revision] = $this->indexed(['src/Example.php' => $content, 'README.md' => 'Not PHP']);
        $this->artisan('thesis:build-chunks', ['revisionId' => $revision->id])
            ->expectsOutputToContain('1 PHP files; 7 chunks (7 created, 0 updated)')->assertSuccessful();
        $chunks = CodeChunk::query()->orderBy('chunk_index')->get();
        $this->assertSame([
            ['file', 1, 4], ['class', 5, 8], ['method', 9, 12],
            ['class', 13, 13], ['file', 14, 14], ['function', 15, 18], ['file', 19, 20],
        ], $chunks->map(fn (CodeChunk $chunk): array => [$chunk->metadata['kind'], $chunk->start_line, $chunk->end_line])->all());
        $this->assertSame($content, $chunks->pluck('content')->implode(''));
        foreach ($chunks as $chunk) {
            $this->assertSame('src/Example.php', $chunk->metadata['path']);
            $this->assertSame(hash('sha256', $content), $chunk->metadata['file_hash']);
            $this->assertSame(hash('sha256', $chunk->content), $chunk->metadata['content_hash']);
            $this->assertSame(PhpCodeChunker::VERSION, $chunk->metadata['algorithm_version']);
            $this->assertSame($revision->id, $chunk->revision->id);
            $this->assertSame('src/Example.php', $chunk->codeFile->path);
        }
        $this->assertSame('Fixtures\Chunks\Greeter::greet', $chunks[2]->codeSymbol->qualified_name);
        $this->assertSame('Fixtures\Chunks\answer', $chunks[5]->metadata['symbol_name']);
    }

    public function test_repeated_execution_is_idempotent_and_symbol_ids_do_not_define_identity(): void
    {
        [$revision, $root] = $this->indexed(['Example.php' => (string) file_get_contents(base_path('tests/Fixtures/CodeChunking/Example.php'))]);
        $builder = app(BuildRevisionChunks::class);
        $builder->handle($revision->id);
        $before = CodeChunk::query()->orderBy('chunk_index')->get()->toArray();
        $this->travel(1)->minutes();
        $this->assertSame(['files' => 1, 'chunks' => 7, 'created' => 0, 'updated' => 0], $builder->handle($revision->id));
        $this->assertSame($before, CodeChunk::query()->orderBy('chunk_index')->get()->toArray());
        $ids = array_column(array_column($before, 'metadata'), 'stable_id');
        app(PhpRevisionAnalyzer::class)->analyze($revision, $root);
        $builder->handle($revision->id);
        $this->assertSame($ids, CodeChunk::query()->orderBy('chunk_index')->get()->pluck('metadata.stable_id')->all());
    }

    public function test_oversized_methods_and_line_endings_preserve_source_without_overlap(): void
    {
        $content = "<?php\r\nclass Large\r\n{\r\n    public function run(): void\r\n    {\r\n"
            .str_repeat("        // deterministic body\r\n", 245)."    }\r\n}";
        [$revision] = $this->indexed(['Large.php' => $content, 'Empty.php' => '', 'SameLine.php' => '<?php class A { public function a() {} public function b() {} }']);
        app(BuildRevisionChunks::class)->handle($revision->id);
        $chunks = CodeChunk::query()->whereHas('codeFile', fn ($query) => $query->where('path', 'Large.php'))->orderBy('chunk_index')->get();
        $this->assertSame($content, $chunks->pluck('content')->implode(''));
        $next = 1;
        foreach ($chunks as $chunk) {
            $this->assertSame($next, $chunk->start_line);
            $this->assertLessThanOrEqual(120, $chunk->end_line - $chunk->start_line + 1);
            $next = $chunk->end_line + 1;
        }
        $this->assertCount(3, $chunks->filter(fn (CodeChunk $chunk): bool => $chunk->metadata['kind'] === 'method'));
        $this->assertSame(0, CodeChunk::query()->whereHas('codeFile', fn ($q) => $q->where('path', 'Empty.php'))->count());
        $sameLine = CodeChunk::query()->whereHas('codeFile', fn ($q) => $q->where('path', 'SameLine.php'))->get();
        $this->assertCount(1, $sameLine);
        $this->assertSame('<?php class A { public function a() {} public function b() {} }', $sameLine->sole()->content);
    }

    public function test_revisions_are_isolated_and_failed_builds_roll_back(): void
    {
        [$first, $root] = $this->indexed(['a.php' => '<?php echo 1;', 'z.php' => '<?php echo 2;']);
        [$second] = $this->indexed(['a.php' => '<?php echo 3;'], $first->project);
        $builder = app(BuildRevisionChunks::class);
        $builder->handle($first->id);
        $builder->handle($second->id);
        $before = CodeChunk::query()->orderBy('id')->get()->toArray();
        $builder->handle($first->id);
        $this->assertSame($before, CodeChunk::query()->orderBy('id')->get()->toArray());
        CodeChunk::query()->where('project_revision_id', $first->id)->orderBy('id')->first()->update(['content' => 'Must survive rollback']);
        $before = CodeChunk::query()->orderBy('id')->get()->toArray();
        file_put_contents($root.'/z.php', '<?php echo 99;');
        $this->artisan('thesis:build-chunks', ['revisionId' => $first->id])->expectsOutputToContain('differs from its indexed hash')->assertFailed();
        $this->assertSame($before, CodeChunk::query()->orderBy('id')->get()->toArray());
    }

    public function test_missing_invalid_and_unindexed_revisions_fail_clearly(): void
    {
        $this->artisan('thesis:build-chunks', ['revisionId' => 'invalid'])->expectsOutputToContain('positive integer')->assertFailed();
        $this->artisan('thesis:build-chunks', ['revisionId' => '0'])->assertFailed();
        $this->artisan('thesis:build-chunks', ['revisionId' => 2147483647])->expectsOutputToContain('does not exist')->assertFailed();
        [$revision] = $this->indexed(['a.php' => '<?php echo 1;']);
        $revision->indexRuns()->update(['status' => 'running']);
        $this->artisan('thesis:build-chunks', ['revisionId' => $revision->id])->expectsOutputToContain('completed PHP index')->assertFailed();
        $this->assertDatabaseCount('code_chunks', 0);
    }

    public function test_missing_snapshot_and_path_traversal_are_rejected(): void
    {
        [$revision] = $this->indexed(['a.php' => '<?php echo 1;']);
        $file = $revision->codeFiles()->sole();
        $file->update(['path' => '../outside.php']);
        $this->artisan('thesis:build-chunks', ['revisionId' => $revision->id])->expectsOutputToContain('Invalid snapshot-relative path')->assertFailed();
        $file->update(['path' => 'missing.php']);
        $this->artisan('thesis:build-chunks', ['revisionId' => $revision->id])->expectsOutputToContain('missing or outside')->assertFailed();
        $revision->update(['metadata' => []]);
        $this->artisan('thesis:build-chunks', ['revisionId' => $revision->id])->expectsOutputToContain('no usable private snapshot')->assertFailed();
        $this->assertDatabaseCount('code_chunks', 0);
    }

    public function test_symbolic_links_cannot_substitute_snapshot_files(): void
    {
        [$revision, $root] = $this->indexed(['a.php' => '<?php echo 1;']);
        $disk = Storage::disk('repository_snapshots');
        $disk->put('outside.php', '<?php echo 1;');
        unlink($root.'/a.php');
        $this->assertTrue(symlink($disk->path('outside.php'), $root.'/a.php'));
        $this->artisan('thesis:build-chunks', ['revisionId' => $revision->id])
            ->expectsOutputToContain('must not contain symbolic links')->assertFailed();
        $this->assertDatabaseCount('code_chunks', 0);
    }

    /** @param array<string, string> $files
     * @return array{ProjectRevision, string}
     */
    private function indexed(array $files, ?Project $project = null): array
    {
        $project ??= User::factory()->create()->projects()->create(['name' => 'Chunk fixture', 'slug' => 'chunk-fixture']);
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

        return [$revision, $root];
    }
}
