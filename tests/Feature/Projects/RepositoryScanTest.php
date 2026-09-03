<?php

namespace Tests\Feature\Projects;

use App\Enums\ProjectSourceType;
use App\Jobs\ScanProjectRepositoryJob;
use App\Models\LocalProjectSource;
use App\Models\Project;
use App\Models\ProjectRevision;
use App\Models\ProjectSource;
use App\Models\User;
use App\ProjectSources\LocalDirectorySource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

class RepositoryScanTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $temporaryPaths = [];

    private string $workspace;

    private string $repository;

    private string $snapshotStorage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = $this->temporaryDirectory('workspace');
        $this->snapshotStorage = $this->temporaryDirectory('snapshots');
        $this->repository = $this->workspace.DIRECTORY_SEPARATOR.'repository';
        File::copyDirectory(
            base_path('tests/Fixtures/Repositories/basic'),
            $this->repository,
        );

        config([
            'codeatlas.local_source_enabled' => true,
            'codeatlas.local_source_root' => $this->workspace,
            'codeatlas.repository_scan.max_file_size' => 1_048_576,
            'codeatlas.repository_scan.max_files' => 20_000,
            'codeatlas.repository_scan.max_entries' => 100_000,
            'codeatlas.repository_scan.max_total_size' => 104_857_600,
            'filesystems.disks.repository_snapshots.root' => $this->snapshotStorage,
        ]);
    }

    public function test_repository_is_materialized_as_a_private_revision_and_inventoried(): void
    {
        [$user, $project] = $this->connectedProject();

        $this->scan($user, $project)->assertSessionHasNoErrors();

        $revision = ProjectRevision::query()->sole();
        $run = $project->indexRuns()->with('steps')->sole();
        $paths = $revision->codeFiles()->orderBy('path')->pluck('path')->all();
        $snapshotRoot = $this->snapshotRoot($revision);

        $this->assertSame([
            'README.md',
            'src/App.php',
            'src/Domain/Thing.ts',
        ], $paths);
        $this->assertSame('completed', $run->status);
        $this->assertSame('completed', $run->steps->sole()->status);
        $this->assertSame(3, $run->statistics['files_discovered']);
        $this->assertDirectoryExists($snapshotRoot);
        $this->assertFileExists($snapshotRoot.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'App.php');
        $this->assertStringStartsWith(
            (string) realpath($this->snapshotStorage),
            (string) realpath($snapshotRoot),
        );
        $this->assertNotSame(realpath($this->repository), realpath($snapshotRoot));

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('project.source.scan.status', 'completed')
                ->where('project.source.scan.revision', $revision->identifier)
                ->where('project.source.scan.files_count', 3)
                ->where('project.source.scan.issues_count', 0)
                ->where('sourceEndpoints.scan', route('projects.scan', $project)),
            );
    }

    public function test_scan_request_queues_the_work_instead_of_scanning_in_the_http_request(): void
    {
        Bus::fake();
        [$user, $project] = $this->connectedProject();

        $this->scan($user, $project)->assertSessionHasNoErrors();

        Bus::assertDispatched(
            ScanProjectRepositoryJob::class,
            fn (ScanProjectRepositoryJob $job): bool => $job->projectId === $project->id,
        );
        $this->assertDatabaseCount('project_revisions', 0);
        $this->assertDatabaseCount('index_runs', 0);
    }

    /** @return array<string, array{string}> */
    public static function excludedPathProvider(): array
    {
        return [
            '.env' => ['.env'],
            '.env.*' => ['.env.production'],
            '.git' => ['.git/config'],
            'vendor' => ['vendor/autoload.php'],
            'node_modules' => ['node_modules/package/index.js'],
            'storage' => ['storage/logs/application.log'],
            'bootstrap cache' => ['bootstrap/cache/packages.php'],
            'PEM private key' => ['secrets/server.pem'],
            'private key' => ['secrets/server.key'],
        ];
    }

    #[DataProvider('excludedPathProvider')]
    public function test_sensitive_and_dependency_paths_are_excluded(string $relativePath): void
    {
        $this->putRepositoryFile($relativePath, 'must not be copied');
        [$user, $project] = $this->connectedProject();

        $this->scan($user, $project)->assertSessionHasNoErrors();

        $revision = ProjectRevision::query()->sole();
        $this->assertDatabaseMissing('code_files', [
            'project_revision_id' => $revision->id,
            'path' => $relativePath,
        ]);
        $this->assertFileDoesNotExist(
            $this->snapshotRoot($revision).DIRECTORY_SEPARATOR.str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $relativePath,
            ),
        );
    }

    public function test_binary_files_are_skipped_and_recorded_as_an_issue(): void
    {
        $this->putRepositoryFile('assets/logo.bin', "image\0binary");
        [$user, $project] = $this->connectedProject();

        $this->scan($user, $project)->assertSessionHasNoErrors();

        $revision = ProjectRevision::query()->sole();
        $this->assertDatabaseMissing('code_files', [
            'project_revision_id' => $revision->id,
            'path' => 'assets/logo.bin',
        ]);
        $this->assertDatabaseHas('analysis_issues', [
            'project_id' => $project->id,
            'project_revision_id' => $revision->id,
            'code' => 'repository.binary_file',
            'source_path' => 'assets/logo.bin',
        ]);
    }

    public function test_oversized_files_are_skipped_using_project_settings(): void
    {
        $this->putRepositoryFile('src/Large.php', str_repeat('x', 32));
        [$user, $project] = $this->connectedProject();
        $project->settings()->create(['max_file_size' => 16]);

        $this->scan($user, $project)->assertSessionHasNoErrors();

        $revision = ProjectRevision::query()->sole();
        $this->assertDatabaseMissing('code_files', [
            'project_revision_id' => $revision->id,
            'path' => 'src/Large.php',
        ]);
        $this->assertDatabaseHas('analysis_issues', [
            'project_id' => $project->id,
            'project_revision_id' => $revision->id,
            'code' => 'repository.file_too_large',
            'source_path' => 'src/Large.php',
        ]);
    }

    public function test_repository_code_is_read_but_never_executed(): void
    {
        $marker = $this->workspace.DIRECTORY_SEPARATOR.'executed.txt';
        $code = '<?php file_put_contents('.var_export($marker, true).", 'executed');";
        $this->putRepositoryFile('danger.php', $code);
        [$user, $project] = $this->connectedProject();

        $this->scan($user, $project)->assertSessionHasNoErrors();

        $this->assertFileDoesNotExist($marker);
        $this->assertDatabaseHas('code_files', [
            'project_id' => $project->id,
            'path' => 'danger.php',
            'content_hash' => hash('sha256', $code),
        ]);
    }

    public function test_path_traversal_in_a_stored_source_is_rejected(): void
    {
        $user = User::factory()->create();
        $project = $this->createProject($user, 'traversal');
        $this->storeLocalSource($project, '../outside');

        $this->scan($user, $project)->assertSessionHasErrors('scan');

        $this->assertDatabaseCount('project_revisions', 0);
        $this->assertDatabaseCount('index_runs', 0);
    }

    public function test_files_outside_the_repository_root_are_never_discovered(): void
    {
        File::put($this->workspace.DIRECTORY_SEPARATOR.'outside.php', '<?php secret();');
        [$user, $project] = $this->connectedProject();

        $this->scan($user, $project)->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('code_files', [
            'project_id' => $project->id,
            'path' => 'outside.php',
        ]);
        $this->assertSame(3, $project->indexRuns()->sole()->statistics['files_discovered']);
    }

    public function test_symlink_escape_is_skipped_and_recorded(): void
    {
        $outside = $this->temporaryDirectory('outside');
        $outsideFile = $outside.DIRECTORY_SEPARATOR.'secret.php';
        File::put($outsideFile, '<?php secret();');
        $link = $this->repository.DIRECTORY_SEPARATOR.'escaped.php';

        if (! @symlink($outsideFile, $link)) {
            $this->markTestSkipped('File symlinks are not available in this environment.');
        }

        [$user, $project] = $this->connectedProject();
        $this->scan($user, $project)->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('code_files', [
            'project_id' => $project->id,
            'path' => 'escaped.php',
        ]);
        $this->assertDatabaseHas('analysis_issues', [
            'project_id' => $project->id,
            'code' => 'repository.symlink_escape',
            'source_path' => 'escaped.php',
        ]);
    }

    public function test_unreadable_or_invalid_file_is_handled_without_failing_the_scan(): void
    {
        $path = $this->repository.DIRECTORY_SEPARATOR.'unreadable.php';
        File::put($path, '<?php return true;');
        @chmod($path, 0000);

        if (is_readable($path)) {
            @chmod($path, 0600);
            File::delete($path);
            $path = $this->repository.DIRECTORY_SEPARATOR.'broken.php';

            if (! @symlink($this->repository.DIRECTORY_SEPARATOR.'missing.php', $path)) {
                $this->markTestSkipped('Unreadable files and file symlinks cannot be created here.');
            }

            $expectedCode = 'repository.symlink_skipped';
        } else {
            $expectedCode = 'repository.unreadable_file';
        }

        try {
            [$user, $project] = $this->connectedProject();
            $this->scan($user, $project)->assertSessionHasNoErrors();
            $this->assertDatabaseHas('analysis_issues', [
                'project_id' => $project->id,
                'code' => $expectedCode,
            ]);
        } finally {
            @chmod($path, 0600);
        }
    }

    public function test_code_files_use_stable_relative_paths_and_deterministic_hashes(): void
    {
        [$user, $project] = $this->connectedProject();
        $expectedContents = File::get(
            $this->repository.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'App.php',
        );

        $this->scan($user, $project)->assertSessionHasNoErrors();

        $file = ProjectRevision::query()->sole()
            ->codeFiles()
            ->where('path', 'src/App.php')
            ->sole();
        $this->assertSame('src/App.php', $file->path);
        $this->assertSame(hash('sha256', $expectedContents), $file->content_hash);
        $this->assertSame('PHP', $file->language);
        $this->assertStringNotContainsString($this->workspace, $file->path);
    }

    public function test_repeated_scan_of_unchanged_repository_reuses_the_revision(): void
    {
        [$user, $project] = $this->connectedProject();

        $this->scan($user, $project)->assertSessionHasNoErrors();
        $firstRevision = ProjectRevision::query()->sole();
        $firstSnapshotState = $this->directoryState($this->snapshotRoot($firstRevision));

        $this->scan($user, $project)->assertSessionHasNoErrors();

        $this->assertDatabaseCount('project_revisions', 1);
        $this->assertDatabaseCount('index_runs', 2);
        $this->assertDatabaseCount('code_files', 3);
        $this->assertSame(
            $firstSnapshotState,
            $this->directoryState($this->snapshotRoot($firstRevision->fresh())),
        );
        $this->assertFalse(
            (bool) $project->indexRuns()->latest('id')->firstOrFail()->statistics['revision_created'],
        );
    }

    public function test_scanning_never_modifies_the_original_repository(): void
    {
        $before = $this->directoryState($this->repository);
        [$user, $project] = $this->connectedProject();

        $this->scan($user, $project)->assertSessionHasNoErrors();

        $this->assertSame($before, $this->directoryState($this->repository));
    }

    public function test_changed_safe_content_creates_a_new_revision(): void
    {
        [$user, $project] = $this->connectedProject();
        $this->scan($user, $project)->assertSessionHasNoErrors();
        $firstIdentifier = ProjectRevision::query()->sole()->identifier;

        $this->putRepositoryFile('src/App.php', '<?php return "changed";');
        $this->scan($user, $project)->assertSessionHasNoErrors();

        $this->assertDatabaseCount('project_revisions', 2);
        $this->assertNotSame(
            $firstIdentifier,
            ProjectRevision::query()->latest('id')->firstOrFail()->identifier,
        );
    }

    public function test_repository_file_count_limit_fails_safely(): void
    {
        config(['codeatlas.repository_scan.max_files' => 2]);
        [$user, $project] = $this->connectedProject();

        $this->scan($user, $project)->assertSessionHasErrors('scan');

        $this->assertDatabaseCount('project_revisions', 0);
        $this->assertDatabaseCount('index_runs', 0);
        $this->assertDirectoryDoesNotExist(
            $this->snapshotStorage.DIRECTORY_SEPARATOR.'projects',
        );
    }

    public function test_scans_and_code_files_are_isolated_by_project_owner(): void
    {
        [$firstOwner, $firstProject] = $this->connectedProject('first-project');
        $secondOwner = User::factory()->create();
        $secondProject = $this->createProject($secondOwner, 'second-project');
        File::copyDirectory(
            base_path('tests/Fixtures/Repositories/basic'),
            $this->workspace.DIRECTORY_SEPARATOR.'repository-two',
        );
        app(LocalDirectorySource::class)->connect($secondProject, 'repository-two');

        $this->scan($firstOwner, $secondProject)->assertForbidden();
        $this->scan($firstOwner, $firstProject)->assertSessionHasNoErrors();
        $this->scan($secondOwner, $secondProject)->assertSessionHasNoErrors();

        $secondRevision = $secondProject->revisions()->sole();
        $this->assertSame(3, $firstProject->revisions()->sole()->codeFiles()->count());
        $this->assertSame(3, $secondRevision->codeFiles()->count());
        $this->assertDatabaseMissing('code_files', [
            'project_id' => $firstProject->id,
            'project_revision_id' => $secondRevision->id,
        ]);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryPaths) as $path) {
            File::deleteDirectory($path);
        }

        parent::tearDown();
    }

    /** @return array{User, Project} */
    private function connectedProject(string $slug = 'repository-scan'): array
    {
        $user = User::factory()->create();
        $project = $this->createProject($user, $slug);
        app(LocalDirectorySource::class)->connect($project, 'repository');

        return [$user, $project];
    }

    private function createProject(User $owner, string $slug): Project
    {
        return $owner->projects()->create([
            'name' => str($slug)->headline()->toString(),
            'slug' => $slug,
            'description' => null,
        ]);
    }

    private function scan(User $user, Project $project): TestResponse
    {
        return $this->actingAs($user)
            ->from(route('projects.show', $project))
            ->post(route('projects.scan', $project));
    }

    private function storeLocalSource(Project $project, string $path): ProjectSource
    {
        $source = $project->sources()->create([
            'type' => ProjectSourceType::LocalDirectory->value,
            'name' => 'Local directory',
        ]);
        $local = new LocalProjectSource(['path' => $path]);
        $local->project()->associate($project);
        $source->local()->save($local);

        return $source;
    }

    private function putRepositoryFile(string $path, string $contents): void
    {
        $absolutePath = $this->repository.DIRECTORY_SEPARATOR.str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $path,
        );
        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, $contents);
    }

    private function snapshotRoot(ProjectRevision $revision): string
    {
        $metadata = $revision->metadata;
        $this->assertIsArray($metadata);
        $this->assertIsArray($metadata['snapshot'] ?? null);
        $path = $metadata['snapshot']['path'] ?? null;
        $this->assertIsString($path);

        return $this->snapshotStorage.DIRECTORY_SEPARATOR.str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $path,
        );
    }

    private function temporaryDirectory(string $label): string
    {
        $path = sys_get_temp_dir()
            .DIRECTORY_SEPARATOR
            .'codeatlas-'.$label.'-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($path);
        $this->temporaryPaths[] = $path;

        return $path;
    }

    /** @return array<string, string> */
    private function directoryState(string $root): array
    {
        $state = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $root,
                RecursiveDirectoryIterator::SKIP_DOTS,
            ),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            $relativePath = str_replace(
                '\\',
                '/',
                substr($entry->getPathname(), strlen(rtrim($root, '/\\')) + 1),
            );

            if ($entry->isLink()) {
                $target = readlink($entry->getPathname());
                $state[$relativePath] = 'link:'.($target === false ? '' : $target);
            } elseif ($entry->isDir()) {
                $state[$relativePath] = 'directory';
            } else {
                $hash = hash_file('sha256', $entry->getPathname());
                $state[$relativePath] = 'file:'.($hash === false ? '' : $hash);
            }
        }

        ksort($state, SORT_STRING);

        return $state;
    }
}
