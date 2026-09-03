<?php

namespace App\Http\Controllers;

use App\Actions\Projects\CreateProject;
use App\Http\Requests\StoreProjectRequest;
use App\Models\IndexRun;
use App\Models\Project;
use App\Models\User;
use App\ProjectSources\LocalDirectorySource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

    public function show(
        Project $project,
        LocalDirectorySource $localDirectory,
    ): Response {
        Gate::authorize('view', $project);

        $project->loadExists('sources');
        $localSourceEnabled = $localDirectory->isEnabled();
        $source = $localSourceEnabled
            ? $localDirectory->findFor($project)
            : null;
        $latestRun = $source === null
            ? null
            : $project->indexRuns()
                ->whereHas(
                    'revision',
                    fn ($query) => $query->where('project_source_id', $source->id),
                )
                ->with('revision')
                ->latest('id')
                ->first();

        return Inertia::render('projects/show', [
            'project' => [
                ...$this->projectData($project),
                'source' => $source?->local === null
                    ? null
                    : [
                        'id' => $source->id,
                        'type' => $source->type,
                        'display_path' => $localDirectory->displayPath($source),
                        'status' => $localDirectory->isAvailable($source)
                            ? 'available'
                            : 'unavailable',
                        'scan' => $latestRun === null
                            ? null
                            : $this->scanData($latestRun),
                    ],
            ],
            'sourceEndpoints' => $localSourceEnabled
                ? [
                    'directories' => route('projects.sources.local.directories', $project),
                    'local' => route('projects.sources.local.update', $project),
                    'scan' => route('projects.scan', $project),
                ]
                : null,
            'localSourceEnabled' => $localSourceEnabled,
            'localSourceConfigured' => $localDirectory->isConfigured(),
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

    /**
     * @return array{
     *     status: string,
     *     revision: string,
     *     files_count: int,
     *     issues_count: int,
     *     completed_at: string|null,
     *     failure_reason: string|null
     * }
     */
    private function scanData(IndexRun $run): array
    {
        $statisticsValue = $run->getAttribute('statistics');
        $statistics = is_array($statisticsValue) ? $statisticsValue : [];
        $completedAt = $run->getAttribute('completed_at');

        return [
            'status' => $run->status,
            'revision' => $run->revision->identifier,
            'files_count' => (int) ($statistics['files_discovered'] ?? 0),
            'issues_count' => (int) ($statistics['issues_count'] ?? 0),
            'completed_at' => $completedAt instanceof Carbon
                ? $completedAt->toISOString()
                : null,
            'failure_reason' => $run->failure_reason,
        ];
    }
}
