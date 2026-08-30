<?php

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProjectManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_list_their_own_projects(): void
    {
        $user = User::factory()->create();
        $project = $this->createProject($user, 'CodeAtlas');

        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/index')
                ->has('projects', 1)
                ->where('projects.0.id', $project->id)
                ->where('projects.0.name', 'CodeAtlas')
                ->where('projects.0.status', 'not_connected'),
            );
    }

    public function test_another_users_projects_are_not_exposed_in_the_index(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $this->createProject($otherUser, 'Private project');

        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/index')
                ->has('projects', 0),
            );
    }

    public function test_authenticated_user_can_create_a_project(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('projects.store'), [
            'name' => 'Customer Portal',
            'description' => 'The customer-facing application.',
        ]);

        $project = Project::query()->sole();

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('projects.show', $project));

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'user_id' => $user->id,
            'name' => 'Customer Portal',
            'slug' => 'customer-portal',
            'description' => 'The customer-facing application.',
        ]);
    }

    public function test_project_creation_validation_works(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('projects.create'))
            ->post(route('projects.store'), [
                'name' => '',
                'description' => str_repeat('a', 5001),
            ])
            ->assertSessionHasErrors(['name', 'description'])
            ->assertRedirect(route('projects.create'));

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_authenticated_user_can_view_their_own_project(): void
    {
        $user = User::factory()->create();
        $project = $this->createProject($user, 'CodeAtlas');

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/show')
                ->where('project.id', $project->id)
                ->where('project.name', 'CodeAtlas')
                ->where('project.status', 'not_connected'),
            );
    }

    public function test_authenticated_user_cannot_view_another_users_project(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $project = $this->createProject($otherUser, 'Private project');

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertForbidden();
    }

    public function test_guests_cannot_access_project_pages(): void
    {
        $owner = User::factory()->create();
        $project = $this->createProject($owner, 'CodeAtlas');

        $this->get(route('projects.index'))
            ->assertRedirect(route('login'));
        $this->get(route('projects.create'))
            ->assertRedirect(route('login'));
        $this->post(route('projects.store'), ['name' => 'Unauthorized'])
            ->assertRedirect(route('login'));
        $this->get(route('projects.show', $project))
            ->assertRedirect(route('login'));
    }

    private function createProject(User $owner, string $name): Project
    {
        return $owner->projects()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'description' => null,
        ]);
    }
}
