<?php

namespace App\Http\Controllers;

use App\Actions\Projects\CreateProject;
use App\Http\Requests\StoreProjectRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Project::class);

        $user = $request->user();
        assert($user instanceof User);

        $projects = $user->projects()
            ->select(['id', 'user_id', 'name', 'slug', 'description', 'created_at'])
            ->withExists('sources')
            ->latest()
            ->get()
            ->map(fn (Project $project): array => $this->projectData($project));

        return Inertia::render('projects/index', [
            'projects' => $projects,
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Project::class);

        return Inertia::render('projects/create');
    }

    public function store(
        StoreProjectRequest $request,
        CreateProject $createProject,
    ): RedirectResponse {
        $user = $request->user();
        assert($user instanceof User);

        $project = $createProject->handle($user, $request->projectData());

        return to_route('projects.show', $project);
    }

    public function show(Project $project): Response
    {
        Gate::authorize('view', $project);

        $project->loadExists('sources');

        return Inertia::render('projects/show', [
            'project' => $this->projectData($project),
        ]);
    }

    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     slug: string,
     *     description: string|null,
     *     status: 'connected'|'not_connected',
     *     created_at: string
     * }
     */
    private function projectData(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'slug' => $project->slug,
            'description' => $project->description,
            'status' => $project->getAttribute('sources_exists')
                ? 'connected'
                : 'not_connected',
            'created_at' => $project->created_at?->toISOString() ?? '',
        ];
    }
}
