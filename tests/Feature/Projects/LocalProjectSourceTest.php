<?php

namespace Tests\Feature\Projects;

use App\Enums\ProjectSourceType;
use App\Models\LocalProjectSource;
use App\Models\Project;
use App\Models\ProjectSource;
use App\Models\User;
use App\ProjectSources\LocalDirectorySource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LocalProjectSourceTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $temporaryPaths = [];

    public function test_existing_project_without_a_source_receives_the_connect_source_props(): void
    {
        $user = User::factory()->create();
        $project = $this->createProject($user);
        $this->configureWorkspace();

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/show')
                ->where('project.id', $project->id)
                ->where('project.source', null)
                ->where('localSourceEnabled', true)
                ->where('localSourceConfigured', true)
                ->where(
                    'sourceEndpoints.local',
                    route('projects.sources.local.update', $project),
                )
                ->where(
                    'sourceEndpoints.directories',
                    route('projects.sources.local.directories', $project),
                ),
            );
    }

    public function test_local_directory_source_ui_and_endpoints_are_unavailable_when_disabled(): void
    {
        $user = User::factory()->create();
        $project = $this->createProject($user);
        $root = $this->configureWorkspace();
        $this->directory($root, 'project-a');

        $this->actingAs($user)->put(
            route('projects.sources.local.update', $project),
            ['path' => 'project-a'],
        );

        config(['codeatlas.local_source_enabled' => false]);

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/show')
                ->where('project.source', null)
                ->where('sourceEndpoints', null)
                ->where('localSourceEnabled', false)
                ->where('localSourceConfigured', false),
            );

        $this->actingAs($user)
            ->getJson(route('projects.sources.local.directories', $project))
            ->assertNotFound();
        $this->actingAs($user)
            ->put(route('projects.sources.local.update', $project), [
                'path' => 'project-a',
            ])
            ->assertNotFound();
        $this->actingAs($user)
            ->delete(route('projects.sources.local.destroy', $project))
            ->assertNotFound();

        $this->assertDatabaseCount('project_sources', 1);
        $this->assertDatabaseCount('local_project_sources', 1);
    }

    public function test_repository_inside_the_configured_root_is_selectable_and_stored_relatively(): void
    {
        $user = User::factory()->create();
        $project = $this->createProject($user);
        $root = $this->configureWorkspace();
        $repository = $this->directory($root, 'project-a');

        $this->actingAs($user)
            ->from(route('projects.show', $project))
            ->put(route('projects.sources.local.update', $project), [
                'path' => 'project-a',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('projects.show', $project));

        $source = ProjectSource::query()->with('local')->sole();

        $this->assertDatabaseHas('project_sources', [
            'id' => $source->id,
            'project_id' => $project->id,
            'type' => ProjectSourceType::LocalDirectory->value,
            'name' => 'Local directory',
        ]);
        $this->assertDatabaseHas('local_project_sources', [
            'project_id' => $project->id,
            'project_source_id' => $source->id,
            'path' => 'project-a',
        ]);
        $this->assertSame(
            realpath($repository),
            app(LocalDirectorySource::class)->runtimePath($source),
        );

        $this->get(route('projects.show', $project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/show')
                ->where('project.source.id', $source->id)
                ->where('project.source.type', 'local_directory')
                ->missing('project.source.path')
                ->where('project.source.display_path', 'project-a')
                ->where('project.source.status', 'available')
                ->where('localSourceConfigured', true),
            );
    }

    public function test_stored_nested_relative_path_resolves_against_the_runtime_root(): void
    {
        $user = User::factory()->create();
        $project = $this->createProject($user);
        $root = $this->configureWorkspace();
        $team = $this->directory($root, 'team');
        $repository = $this->directory($team, 'project-a');

        $this->actingAs($user)->put(
            route('projects.sources.local.update', $project),
            ['path' => 'team/project-a'],
        );

        $source = ProjectSource::query()->with('local')->sole();

        $this->assertSame('team/project-a', $source->local?->path);
        $this->assertSame(
            realpath($repository),
            app(LocalDirectorySource::class)->runtimePath($source),
        );
    }

    public function test_legacy_absolute_path_is_not_resolved_or_displayed(): void
    {
        $user = User::factory()->create();
        $project = $this->createProject($user);
        $root = $this->configureWorkspace();
        $repository = $this->directory($root, 'legacy-project');
        $source = $project->sources()->create([
            'type' => ProjectSourceType::LocalDirectory->value,
            'name' => 'Local directory',
        ]);
        $localSource = new LocalProjectSource([
            'path' => (string) realpath($repository),
        ]);
        $localSource->project()->associate($project);
        $source->local()->save($localSource);
        $source->setRelation('local', $localSource);

        $localDirectory = app(LocalDirectorySource::class);

        $this->assertFalse($localDirectory->isAvailable($source));
        $this->assertNull($localDirectory->displayPath($source));
        $this->assertNull($localDirectory->runtimePath($source));
    }

    public function test_local_source_relationships_are_connected_to_the_same_project(): void
    {
        $user = User::factory()->create();
        $project = $this->createProject($user);
        $root = $this->configureWorkspace();
        $this->directory($root, 'project-a');

        $this->actingAs($user)->put(
            route('projects.sources.local.update', $project),
            ['path' => 'project-a'],
        );

        $source = ProjectSource::query()->with('local')->sole();
        $localSource = LocalProjectSource::query()->sole();

        $this->assertTrue($source->project->is($project));
        $this->assertTrue($source->local?->is($localSource));
        $this->assertTrue($localSource->project->is($project));
        $this->assertTrue($localSource->source->is($source));
        $this->assertTrue($project->fresh()->sources->contains($source));
    }

    public function test_directory_browser_is_rooted_at_the_configured_workspace(): void
    {
        $user = User::factory()->create();
        $project = $this->createProject($user);
        $root = $this->configureWorkspace();
        $visibleDirectory = $this->directory($root, 'visible-project');
        $this->directory($root, '.hidden');
        $this->file($root, 'readme.txt');
        $this->directory($visibleDirectory, 'src');

        $response = $this->actingAs($user)
            ->getJson(route('projects.sources.local.directories', $project))
            ->assertOk()
            ->assertJsonPath('current_path', '')
            ->assertJsonPath('current_display_path', '')
            ->assertJsonPath('parent_path', null)
            ->assertJsonCount(1, 'directories')
            ->assertJsonPath('directories.0.name', 'visible-project')
            ->assertJsonPath('directories.0.path', 'visible-project')
            ->assertJsonMissing(['name' => '.hidden'])
            ->assertJsonMissing(['name' => 'readme.txt']);

        $this->assertArrayNotHasKey('root_path', $response->json());
        $this->assertArrayNotHasKey('roots', $response->json());

        $this->actingAs($user)
            ->getJson(route('projects.sources.local.directories', [
                'project' => $project,
                'path' => 'visible-project',
            ]))
            ->assertOk()
            ->assertJsonPath('current_path', 'visible-project')
            ->assertJsonPath('parent_path', '')
            ->assertJsonPath('directories.0.path', 'visible-project/src');
    }

    public function test_repository_outside_the_configured_root_is_rejected(): void
    {
        $user = User::factory()->create();
        $project = $this->createProject($user);
        $this->configureWorkspace();
        $outside = $this->temporaryDirectory();

        $this->actingAs($user)
            ->put(route('projects.sources.local.update', $project), [
                'path' => $outside,
            ])
            ->assertSessionHasErrors('path');

        $this->actingAs($user)
            ->getJson(route('projects.sources.local.directories', [
                'project' => $project,
                'path' => $outside,
            ]))
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                __('ui.projects.sources.browser.unavailable'),
            );

        $this->assertDatabaseCount('project_sources', 0);
        $this->assertDatabaseCount('local_project_sources', 0);
    }

    public function test_parent_directory_traversal_is_rejected(): void
    {
        $user = User::factory()->create();
        $project = $this->createProject($user);
        $this->configureWorkspace();

        $this->actingAs($user)
            ->put(route('projects.sources.local.update', $project), [
                'path' => '../outside-project',
            ])
            ->assertSessionHasErrors('path');

        $this->actingAs($user)
            ->getJson(route('projects.sources.local.directories', [
                'project' => $project,
                'path' => '../outside-project',
            ]))
            ->assertUnprocessable();

        $this->assertDatabaseCount('project_sources', 0);
    }

    public function test_symlink_that_escapes_the_configured_root_is_rejected(): void
    {
        $user = User::factory()->create();
        $project = $this->createProject($user);
        $root = $this->configureWorkspace();
        $outside = $this->temporaryDirectory();
        $link = $root.DIRECTORY_SEPARATOR.'escaped-project';

        if (! @symlink($outside, $link)) {
            $this->markTestSkipped('Directory symlinks are not available in this environment.');
        }

        $this->temporaryPaths[] = $link;

        $this->actingAs($user)
            ->put(route('projects.sources.local.update', $project), [
                'path' => 'escaped-project',
            ])
            ->assertSessionHasErrors('path');

        $this->actingAs($user)
            ->getJson(route('projects.sources.local.directories', $project))
            ->assertOk()
            ->assertJsonMissing(['name' => 'escaped-project']);

        $this->assertDatabaseCount('project_sources', 0);
    }

    public function test_missing_repository_and_files_are_rejected(): void
    {
        $user = User::factory()->create();
        $project = $this->createProject($user);
        $root = $this->configureWorkspace();
        $this->file($root, 'not-a-directory.txt');

        $this->actingAs($user)
            ->put(route('projects.sources.local.update', $project), [
                'path' => 'missing-project',
            ])
            ->assertSessionHasErrors('path');

        $this->actingAs($user)
            ->put(route('projects.sources.local.update', $project), [
                'path' => 'not-a-directory.txt',
            ])
            ->assertSessionHasErrors('path');

        $this->assertDatabaseCount('project_sources', 0);
    }

    public function test_another_users_source_and_directory_browser_cannot_be_accessed(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = $this->createProject($owner);
        $root = $this->configureWorkspace();
        $this->directory($root, 'project-a');

        $this->actingAs($owner)->put(
            route('projects.sources.local.update', $project),
            ['path' => 'project-a'],
        );

        $this->actingAs($intruder)
            ->get(route('projects.show', $project))
            ->assertForbidden();
        $this->actingAs($intruder)
            ->getJson(route('projects.sources.local.directories', $project))
            ->assertForbidden();
        $this->actingAs($intruder)
            ->put(route('projects.sources.local.update', $project), [
                'path' => 'project-a',
            ])
            ->assertForbidden();
        $this->actingAs($intruder)
            ->delete(route('projects.sources.local.destroy', $project))
            ->assertForbidden();

        $this->assertDatabaseCount('project_sources', 1);
        $this->assertDatabaseCount('local_project_sources', 1);
    }

    public function test_missing_workspace_configuration_is_handled_cleanly(): void
    {
        $user = User::factory()->create();
        $project = $this->createProject($user);
        config([
            'codeatlas.local_source_enabled' => true,
            'codeatlas.local_source_root' => sys_get_temp_dir()
                .DIRECTORY_SEPARATOR
                .'codeatlas-missing-'
                .bin2hex(random_bytes(8)),
        ]);

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('project.source', null)
                ->where('localSourceConfigured', false),
            );

        $this->actingAs($user)
            ->getJson(route('projects.sources.local.directories', $project))
            ->assertStatus(503)
            ->assertJsonPath(
                'message',
                __('ui.projects.sources.configuration.description'),
            );

        $this->actingAs($user)
            ->put(route('projects.sources.local.update', $project), [
                'path' => 'project-a',
            ])
            ->assertSessionHasErrors('path');

        $this->assertDatabaseCount('project_sources', 0);
    }

    public function test_user_can_replace_and_remove_their_local_source(): void
    {
        $user = User::factory()->create();
        $project = $this->createProject($user);
        $root = $this->configureWorkspace();
        $this->directory($root, 'first-project');
        $this->directory($root, 'replacement-project');

        $this->actingAs($user)->put(
            route('projects.sources.local.update', $project),
            ['path' => 'first-project'],
        );
        $source = ProjectSource::query()->sole();

        $this->actingAs($user)
            ->from(route('projects.show', $project))
            ->put(route('projects.sources.local.update', $project), [
                'path' => 'replacement-project',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('projects.show', $project));

        $this->assertDatabaseCount('project_sources', 1);
        $this->assertDatabaseCount('local_project_sources', 1);
        $this->assertDatabaseHas('local_project_sources', [
            'project_source_id' => $source->id,
            'path' => 'replacement-project',
        ]);

        $this->actingAs($user)
            ->from(route('projects.show', $project))
            ->delete(route('projects.sources.local.destroy', $project))
            ->assertRedirect(route('projects.show', $project));

        $this->assertDatabaseCount('project_sources', 0);
        $this->assertDatabaseCount('local_project_sources', 0);
    }

    public function test_guests_cannot_connect_remove_or_browse_a_local_source(): void
    {
        $owner = User::factory()->create();
        $project = $this->createProject($owner);

        $this->put(route('projects.sources.local.update', $project), [
            'path' => 'project-a',
        ])->assertRedirect(route('login'));

        $this->delete(route('projects.sources.local.destroy', $project))
            ->assertRedirect(route('login'));

        $this->get(route('projects.sources.local.directories', $project))
            ->assertRedirect(route('login'));
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryPaths) as $path) {
            if (is_link($path) || is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                rmdir($path);
            }
        }

        parent::tearDown();
    }

    private function createProject(User $owner): Project
    {
        return $owner->projects()->create([
            'name' => 'CodeAtlas',
            'slug' => 'codeatlas',
            'description' => null,
        ]);
    }

    private function configureWorkspace(): string
    {
        $root = $this->temporaryDirectory();
        config([
            'codeatlas.local_source_enabled' => true,
            'codeatlas.local_source_root' => $root,
        ]);

        return $root;
    }

    private function temporaryDirectory(): string
    {
        $path = sys_get_temp_dir()
            .DIRECTORY_SEPARATOR
            .'codeatlas-source-'
            .bin2hex(random_bytes(8));

        mkdir($path);
        $this->temporaryPaths[] = $path;

        return $path;
    }

    private function directory(string $parent, string $name): string
    {
        $path = $parent.DIRECTORY_SEPARATOR.$name;

        mkdir($path);
        $this->temporaryPaths[] = $path;

        return $path;
    }

    private function file(string $parent, string $name): string
    {
        $path = $parent.DIRECTORY_SEPARATOR.$name;

        file_put_contents($path, 'test');
        $this->temporaryPaths[] = $path;

        return $path;
    }
}
