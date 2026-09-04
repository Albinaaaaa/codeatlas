<?php

namespace Tests\Feature\RepositoryAnalysis;

use App\Models\CodeFile;
use App\Models\Project;
use App\Models\ProjectRevision;
use App\Models\User;
use App\PhpAnalysis\PhpFileAnalyzer;
use App\PhpAnalysis\PhpFileInput;
use App\PhpAnalysis\PhpRevisionAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PhpAstAnalyzerTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixture_extracts_all_supported_symbols_and_relations_deterministically(): void
    {
        $input = new PhpFileInput(
            codeFileId: 17,
            path: 'Supported.php',
            contents: $this->fixture('Supported.php'),
        );
        $analyzer = app(PhpFileAnalyzer::class);

        $first = $analyzer->analyze($input);
        $second = $analyzer->analyze($input);

        $this->assertEquals($first, $second);
        $this->assertSame([], $first->issues);
        $this->assertSame([
            'namespace',
            'interface',
            'interface',
            'trait',
            'class',
            'enum',
            'class_constant',
            'method',
            'class',
            'class_constant',
            'property',
            'method',
            'property',
            'method',
            'method',
        ], array_map(fn ($symbol): string => $symbol->kind, $first->symbols));
        $this->assertSame([
            ['extends', 'Fixtures\\Domain\\Contract'],
            ['implements', 'Fixtures\\Domain\\Contract'],
            ['extends', 'Fixtures\\Domain\\BaseService'],
            ['implements', 'Fixtures\\Domain\\Contract'],
            ['implements', 'Countable'],
            ['trait_use', 'Fixtures\\Domain\\Logs'],
        ], array_map(
            fn ($relation): array => [$relation->type, $relation->targetName],
            $first->relations,
        ));
        $this->assertContains('Fixtures\\Domain\\Service::$id', array_column($first->symbols, 'qualifiedName'));
    }

    public function test_parse_and_unsupported_construct_failures_are_returned_as_issues(): void
    {
        $analyzer = app(PhpFileAnalyzer::class);
        $broken = $analyzer->analyze(new PhpFileInput(1, 'Broken.php', $this->fixture('Broken.php.fixture')));
        $unsupported = $analyzer->analyze(new PhpFileInput(2, 'Unsupported.php', $this->fixture('Unsupported.php')));

        $this->assertSame('php.parse_error', $broken->issues[0]->code);
        $this->assertSame([], $broken->symbols);
        $this->assertSame('php.unsupported_anonymous_class', $unsupported->issues[0]->code);
        $this->assertSame('namespace', $unsupported->symbols[0]->kind);
    }

    public function test_revision_analysis_persists_provenance_and_resolves_internal_targets(): void
    {
        [$project, $revision] = $this->revision();
        $supported = $this->codeFile($revision, 'Supported.php');
        $broken = $this->codeFile($revision, 'Broken.php.fixture');
        $analyzer = app(PhpRevisionAnalyzer::class);

        $first = $analyzer->analyze($revision, base_path('tests/Fixtures/PHP'));
        $second = $analyzer->analyze($revision, base_path('tests/Fixtures/PHP'));

        $this->assertEquals($first, $second);
        $this->assertSame(2, $first->filesAnalyzed);
        $this->assertSame(15, $first->symbolsPersisted);
        $this->assertSame(6, $first->relationsPersisted);
        $this->assertSame(1, $first->issuesPersisted);
        $this->assertDatabaseCount('code_symbols', 15);
        $this->assertDatabaseCount('code_relations', 6);
        $this->assertDatabaseHas('analysis_issues', [
            'project_id' => $project->id,
            'project_revision_id' => $revision->id,
            'code_file_id' => $broken->id,
            'category' => 'php_ast',
            'code' => 'php.parse_error',
            'source_path' => 'Broken.php.fixture',
        ]);
        $this->assertSame(0, DB::table('code_symbols')
            ->where('project_id', $project->id)
            ->where('project_revision_id', $revision->id)
            ->where('code_file_id', $supported->id)
            ->whereNull('start_line')
            ->count());
        $this->assertSame(0, DB::table('code_symbols')
            ->where('code_file_id', $supported->id)
            ->whereNull('end_line')
            ->count());
        $this->assertSame(5, DB::table('code_relations')->whereNotNull('to_symbol_id')->count());
        $this->assertDatabaseHas('code_relations', [
            'project_id' => $project->id,
            'project_revision_id' => $revision->id,
            'code_file_id' => $supported->id,
            'type' => 'implements',
            'target_name' => 'Countable',
            'to_symbol_id' => null,
        ]);
    }

    /** @return array{Project, ProjectRevision} */
    private function revision(): array
    {
        $project = User::factory()->create()->projects()->create([
            'name' => 'PHP AST fixture',
            'slug' => 'php-ast-fixture',
        ]);
        $source = $project->sources()->create([
            'type' => 'local',
            'name' => 'Generic revision source',
        ]);
        $revision = new ProjectRevision(['identifier' => 'php-ast-fixture']);
        $revision->project()->associate($project);
        $source->revisions()->save($revision);

        return [$project, $revision];
    }

    private function codeFile(ProjectRevision $revision, string $path): CodeFile
    {
        $contents = $this->fixture($path);

        return CodeFile::forceCreate([
            'project_id' => $revision->project_id,
            'project_revision_id' => $revision->id,
            'path' => $path,
            'language' => 'PHP',
            'content_hash' => hash('sha256', $contents),
            'size_bytes' => strlen($contents),
            'line_count' => substr_count($contents, "\n"),
        ]);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/PHP/'.$name));
    }
}
