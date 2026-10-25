<?php

namespace Tests\Concerns;

use App\CodeChunking\BuildRevisionChunks;
use App\Models\IndexRun;
use App\Models\Project;
use App\Models\ProjectRevision;
use App\Models\User;
use App\PhpAnalysis\PhpRevisionAnalyzer;
use App\RepositoryScanning\RepositoryInventoryScanner;
use App\RepositoryScanning\RepositorySnapshotMaterializer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

trait ResearchFixture
{
    protected function fakeResearchStorage(): void
    {
        Storage::fake('repository_snapshots');
        config(['filesystems.disks.repository_snapshots.root' => Storage::disk('repository_snapshots')->path('')]);
        Storage::fake('research_datasets');
        Http::preventStrayRequests();
    }

    /** @return array{revision: ProjectRevision, row: array<string, mixed>, path: string, root: string} */
    protected function researchFixture(?Project $project = null): array
    {
        $project ??= User::factory()->create()->projects()->create(['name' => 'Research fixture', 'slug' => 'research-fixture']);
        $source = $project->sources()->create(['name' => 'Fixture-'.$project->sources()->count(), 'type' => 'local']);
        $snapshot = app(RepositorySnapshotMaterializer::class)->materialize($project, $source, base_path('tests/Fixtures/ResearchDataset/repository'));
        app(RepositoryInventoryScanner::class)->scan($snapshot);
        $analysis = app(PhpRevisionAnalyzer::class)->analyze($snapshot->revision, $snapshot->rootPath);
        $run = new IndexRun(['status' => 'completed', 'trigger' => 'manual', 'statistics' => ['php_files_analyzed' => $analysis->filesAnalyzed]]);
        $run->project()->associate($project);
        $snapshot->revision->indexRuns()->save($run);
        app(BuildRevisionChunks::class)->handle($snapshot->revision->id);
        $row = json_decode((string) file_get_contents(base_path('tests/Fixtures/ResearchDataset/pilot.jsonl')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($row['revision_fingerprint'], $snapshot->fingerprint);
        $row['revision_id'] = (int) $snapshot->revision->id;

        return ['revision' => $snapshot->revision, 'row' => $row, 'path' => $this->datasetFile([$row]), 'root' => $snapshot->rootPath];
    }

    /** @param list<array<string, mixed>> $rows */
    protected function datasetFile(array $rows): string
    {
        $name = Str::uuid().'.jsonl';
        $raw = implode("\n", array_map(fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $rows))."\n";
        Storage::disk('research_datasets')->put($name, $raw);

        return Storage::disk('research_datasets')->path($name);
    }
}
