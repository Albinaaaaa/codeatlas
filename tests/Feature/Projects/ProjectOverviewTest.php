<?php

namespace Tests\Feature\Projects;

use App\Actions\Projects\GenerateProjectProfile;
use App\Models\ProjectRevision;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\RefreshDatabase;
use Tests\TestCase;

class ProjectOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_is_generated_only_from_the_selected_revision_facts(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create([
            'name' => 'Overview project',
            'slug' => 'overview-project',
        ]);
        $source = $project->sources()->create(['type' => 'local', 'name' => 'workspace']);
        $revision = new ProjectRevision(['identifier' => 'revision-1']);
        $revision->project()->associate($project);
        $revision->source()->associate($source);
        $revision->save();
        $otherRevision = $revision->replicate();
        $otherRevision->identifier = 'other-revision';
        $otherRevision->save();
        DB::table('code_files')->insert([
            'project_id' => $project->id,
            'project_revision_id' => $otherRevision->id,
            'path' => 'other.php',
            'language' => 'PHP',
            'content_hash' => str_repeat('b', 64),
        ]);
        DB::table('code_files')->insert([
            'project_id' => $project->id,
            'project_revision_id' => $revision->id,
            'path' => 'app/Models/User.php',
            'language' => 'PHP',
            'content_hash' => str_repeat('a', 64),
            'size_bytes' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('laravel_routes')->insert([
            'project_id' => $project->id,
            'project_revision_id' => $revision->id,
            'method' => 'GET',
            'uri' => 'users',
            'middleware' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(GenerateProjectProfile::class)->handle($revision);

        $this->assertDatabaseHas('project_profiles', [
            'project_id' => $project->id,
            'primary_language' => 'PHP',
            'framework' => 'Laravel',
        ]);
        $this->assertDatabaseHas('project_technologies', [
            'project_id' => $project->id,
            'name' => 'Laravel',
            'category' => 'framework',
        ]);
        $this->assertStringContainsString('1 files', (string) $project->profile()->value('summary'));
    }

    public function test_new_profile_removes_obsolete_technologies_and_older_analysis_cannot_overwrite_it(): void
    {
        $project = User::factory()->create()->projects()->create([
            'name' => 'Changing project', 'slug' => 'changing-project',
        ]);
        $source = $project->sources()->create(['type' => 'local', 'name' => 'workspace']);
        $oldRevision = new ProjectRevision(['identifier' => 'old-revision']);
        $oldRevision->project()->associate($project);
        $source->revisions()->save($oldRevision);
        DB::table('code_files')->insert([
            'project_revision_id' => $oldRevision->id,
            'project_id' => $project->id, 'path' => 'artisan',
            'language' => 'PHP', 'content_hash' => str_repeat('a', 64),
        ]);
        $generator = app(GenerateProjectProfile::class);
        $generator->handle($oldRevision);
        $this->assertSame(2, $project->technologies()->count());

        $newRevision = new ProjectRevision(['identifier' => 'new-revision']);
        $newRevision->project()->associate($project);
        $source->revisions()->save($newRevision);
        $generator->handle($newRevision);
        $generator->handle($oldRevision);

        $profile = $project->profile()->sole();
        $this->assertSame($newRevision->id, $profile->metadata['project_revision_id']);
        $this->assertNull($profile->primary_language);
        $this->assertNull($profile->architecture);
        $this->assertNull($profile->framework);
        $this->assertSame(0, $project->technologies()->count());
    }

    public function test_overview_only_shows_facts_and_profile_for_the_latest_revision(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create([
            'name' => 'Revision overview', 'slug' => 'revision-overview',
        ]);
        $source = $project->sources()->create(['type' => 'local', 'name' => 'workspace']);
        $oldRevision = new ProjectRevision(['identifier' => 'old-revision']);
        $oldRevision->project()->associate($project);
        $source->revisions()->save($oldRevision);
        DB::table('code_files')->insert([
            'project_revision_id' => $oldRevision->id,
            'project_id' => $project->id, 'path' => 'old.php',
            'language' => 'PHP', 'content_hash' => str_repeat('a', 64),
        ]);
        app(GenerateProjectProfile::class)->handle($oldRevision);
        $revision = new ProjectRevision(['identifier' => 'latest-revision']);
        $revision->project()->associate($project);
        $source->revisions()->save($revision);

        $this->actingAs($user)->get(route('projects.show', $project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('overview.revision.identifier', 'latest-revision')
                ->where('overview.last_analysis', null)
                ->where('overview.statistics.files', 0)
                ->where('overview.profile', null)
                ->has('overview.technologies', 0)
                ->has('overview.async', 0)
                ->has('overview.issues', 0));

        app(GenerateProjectProfile::class)->handle($revision);
        $this->actingAs($user)->get(route('projects.show', $project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('overview.profile.summary', fn ($summary): bool => str_contains($summary, 'latest-revision')));
    }
}
