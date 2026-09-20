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
use Illuminate\Support\Facades\DB;
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
            'routes' => $this->routesFor($project),
            'models' => $this->modelsFor($project),
            'asyncStructure' => [],
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

    /**
     * @return list<array{
     *   id: int, code_symbol_id: int, class: string, table_name: string|null,
     *   connection: string|null, source_path: string, start_line: int|null, end_line: int|null,
     *   configuration: array<string, mixed>, relations: list<array<string, mixed>>
     * }>
     */
    private function modelsFor(Project $project): array
    {
        $revisionId = $project->revisions()->max('id');
        if ($revisionId === null) {
            return [];
        }

        $relations = DB::table('laravel_model_relations as relations')
            ->join('code_files as files', 'files.id', '=', 'relations.code_file_id')
            ->where('relations.project_id', $project->id)
            ->where('relations.project_revision_id', $revisionId)
            ->orderBy('relations.name')
            ->select('relations.*', 'files.path as source_path')
            ->get()
            ->groupBy('laravel_model_id');

        return DB::table('laravel_models as models')
            ->join('code_symbols as symbols', 'symbols.id', '=', 'models.code_symbol_id')
            ->join('code_files as files', 'files.id', '=', 'symbols.code_file_id')
            ->where('models.project_id', $project->id)
            ->where('models.project_revision_id', $revisionId)
            ->orderBy('symbols.qualified_name')
            ->select('models.*', 'symbols.qualified_name', 'symbols.start_line', 'symbols.end_line', 'files.path as source_path')
            ->get()
            ->map(function (object $model) use ($relations): array {
                $metadata = json_decode((string) $model->metadata, true, flags: JSON_THROW_ON_ERROR);

                return [
                    'id' => (int) $model->id,
                    'code_symbol_id' => (int) $model->code_symbol_id,
                    'class' => (string) $model->qualified_name,
                    'table_name' => is_string($model->table_name) ? $model->table_name : null,
                    'connection' => is_string($model->connection) ? $model->connection : null,
                    'source_path' => (string) $model->source_path,
                    'start_line' => $model->start_line === null ? null : (int) $model->start_line,
                    'end_line' => $model->end_line === null ? null : (int) $model->end_line,
                    'configuration' => $metadata['configuration'] ?? [],
                    'relations' => ($relations->get($model->id) ?? collect())
                        ->map(function (object $relation): array {
                            $metadata = json_decode((string) $relation->metadata, true, flags: JSON_THROW_ON_ERROR);

                            return [
                                'id' => (int) $relation->id,
                                'name' => (string) $relation->name,
                                'relation_type' => (string) $relation->relation_type,
                                'related_model' => (string) $relation->related_model,
                                'related_laravel_model_id' => $relation->related_laravel_model_id === null ? null : (int) $relation->related_laravel_model_id,
                                'foreign_key' => $relation->foreign_key,
                                'local_key' => $relation->local_key,
                                'pivot_table' => $relation->pivot_table,
                                'source_path' => (string) $relation->source_path,
                                'start_line' => $relation->start_line === null ? null : (int) $relation->start_line,
                                'end_line' => $relation->end_line === null ? null : (int) $relation->end_line,
                                'arguments' => $metadata['arguments'] ?? [],
                            ];
                        })->values()->all(),
                ];
            })->all();
    }

    /**
     * @return list<array{
     *     id: int,
     *     method: string,
     *     uri: string,
     *     name: string|null,
     *     controller: string|null,
     *     middleware: list<string>,
     *     source_path: string,
     *     start_line: int|null,
     *     end_line: int|null
     * }>
     */
    private function routesFor(Project $project): array
    {
        $revisionId = $project->revisions()->max('id');

        if ($revisionId === null) {
            return [];
        }

        return DB::table('laravel_routes')
            ->join('code_files', function ($join): void {
                $join->on('code_files.project_id', '=', 'laravel_routes.project_id')
                    ->on('code_files.project_revision_id', '=', 'laravel_routes.project_revision_id')
                    ->on('code_files.id', '=', 'laravel_routes.code_file_id');
            })
            ->where('laravel_routes.project_id', $project->id)
            ->where('laravel_routes.project_revision_id', $revisionId)
            ->orderBy('laravel_routes.uri')
            ->orderBy('laravel_routes.method')
            ->select([
                'laravel_routes.id',
                'laravel_routes.method',
                'laravel_routes.uri',
                'laravel_routes.name',
                'laravel_routes.action',
                'laravel_routes.middleware',
                'laravel_routes.start_line',
                'laravel_routes.end_line',
                'code_files.path as source_path',
            ])
            ->get()
            ->map(function (object $route): array {
                $middleware = is_string($route->middleware)
                    ? json_decode($route->middleware, true)
                    : $route->middleware;

                return [
                    'id' => (int) $route->id,
                    'method' => (string) $route->method,
                    'uri' => (string) $route->uri,
                    'name' => is_string($route->name) ? $route->name : null,
                    'controller' => $route->action === 'Closure' ? null : (string) $route->action,
                    'middleware' => is_array($middleware)
                        ? array_values(array_filter($middleware, is_string(...)))
                        : [],
                    'source_path' => (string) $route->source_path,
                    'start_line' => $route->start_line === null ? null : (int) $route->start_line,
                    'end_line' => $route->end_line === null ? null : (int) $route->end_line,
                ];
            })
            ->all();
    }
}
