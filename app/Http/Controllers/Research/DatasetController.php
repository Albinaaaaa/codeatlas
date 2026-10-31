<?php

namespace App\Http\Controllers\Research;

use App\Actions\Research\ManageResearchDataset;
use App\Http\Controllers\Controller;
use App\Http\Requests\Research\StoreDatasetRequest;
use App\Http\Requests\Research\UpdateDatasetRequest;
use App\Models\Project;
use App\Models\ResearchDataset;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DatasetController extends Controller
{
    public function index(Project $project): Response
    {
        Gate::authorize('view', $project);

        return Inertia::render('projects/research/datasets/index', [
            'project' => $project->only(['id', 'name']),
            'endpoints' => ['index' => route('projects.research.datasets.index', $project),
                'experiments' => route('projects.research.experiments.index', $project)],
            'datasets' => ResearchDataset::query()->where('project_id', $project->id)->latest('id')
                ->get(['id', 'dataset_id', 'dataset_version', 'split', 'status', 'created_at'])
                ->map(fn (ResearchDataset $dataset): array => [...$dataset->toArray(), 'url' => route('projects.research.datasets.show', [$project, $dataset])]),
            'revisions' => $project->revisions()->whereHas('indexRuns', fn ($q) => $q->where('status', 'completed'))
                ->latest('id')->get(['id', 'identifier'])->toArray(),
        ]);
    }

    public function store(StoreDatasetRequest $request, Project $project, ManageResearchDataset $datasets): RedirectResponse
    {
        $dataset = $datasets->create($project, $request->validated(), $request->file('upload'));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('ui.research.created')]);

        return to_route('projects.research.datasets.show', [$project, $dataset]);
    }

    public function show(Project $project, ResearchDataset $dataset, ManageResearchDataset $datasets): Response
    {
        $this->authorizeDataset($project, $dataset);
        $report = $dataset->validation_report;
        if (is_array($report)) {
            $report['errors'] = array_map(fn (array $error): array => [
                ...$error, 'field' => $datasets->errorField($error['message']), 'message' => $datasets->errorMessage($error['message']),
            ], $report['errors']);
        }

        return Inertia::render('projects/research/datasets/show', [
            'project' => $project->only(['id', 'name']),
            'dataset' => [
                ...$dataset->only(['id', 'dataset_id', 'dataset_version', 'split', 'status', 'lock_version', 'original_sha256', 'frozen_sha256', 'frozen_at']),
                'questions' => array_map(fn (array $row): array => array_intersect_key($row, array_flip([
                    'question_id', 'category', 'question', 'reference_answer', 'required_facts', 'evidence', 'verification',
                ])), $dataset->questionRows()),
                'revision' => $dataset->revision->only(['id', 'identifier']),
                'validation_report' => $report,
            ],
            'endpoints' => [
                'index' => route('projects.research.datasets.index', $project),
                'show' => route('projects.research.datasets.show', [$project, $dataset]),
                'experiments' => route('projects.research.experiments.index', $project),
            ],
        ]);
    }

    public function addQuestion(UpdateDatasetRequest $request, Project $project, ResearchDataset $dataset, ManageResearchDataset $datasets): RedirectResponse
    {
        return $this->change($request, $project, $dataset, $datasets, 'add');
    }

    public function updateQuestion(UpdateDatasetRequest $request, Project $project, ResearchDataset $dataset, int $question, ManageResearchDataset $datasets): RedirectResponse
    {
        return $this->change($request, $project, $dataset, $datasets, 'edit', $question);
    }

    public function verify(UpdateDatasetRequest $request, Project $project, ResearchDataset $dataset, int $question, ManageResearchDataset $datasets): RedirectResponse
    {
        return $this->change($request, $project, $dataset, $datasets, 'verify', $question);
    }

    public function validateDataset(UpdateDatasetRequest $request, Project $project, ResearchDataset $dataset, ManageResearchDataset $datasets): RedirectResponse
    {
        return $this->change($request, $project, $dataset, $datasets, 'validate');
    }

    public function freeze(UpdateDatasetRequest $request, Project $project, ResearchDataset $dataset, ManageResearchDataset $datasets): RedirectResponse
    {
        return $this->change($request, $project, $dataset, $datasets, 'freeze');
    }

    private function authorizeDataset(Project $project, ResearchDataset $dataset): void
    {
        Gate::authorize('view', $project);
        abort_unless((int) $dataset->project_id === (int) $project->id, 404);
    }

    private function change(UpdateDatasetRequest $request, Project $project, ResearchDataset $dataset, ManageResearchDataset $datasets, string $operation, ?int $question = null): RedirectResponse
    {
        $this->authorizeDataset($project, $dataset);
        $user = $request->user();
        assert($user instanceof User);
        $datasets->change($dataset, $request->integer('lock_version'), $operation, $user, $question, $request->validated());
        $report = $dataset->fresh()?->validation_report;
        $invalid = is_array($report) && $report['errors'] !== [];
        Inertia::flash('toast', ['type' => $invalid ? 'error' : 'success',
            'message' => __('ui.research.'.($invalid ? 'validation_failed' : 'saved'))]);

        return back();
    }
}
