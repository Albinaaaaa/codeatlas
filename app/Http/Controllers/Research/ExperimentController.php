<?php

namespace App\Http\Controllers\Research;

use App\Actions\Research\ManageResearchDataset;
use App\Http\Controllers\Controller;
use App\Http\Requests\Research\StoreResearchExperimentRequest;
use App\Jobs\PrepareResearchExperimentJob;
use App\Jobs\RunResearchExperimentBatchJob;
use App\Models\Project;
use App\Models\ResearchDataset;
use App\Models\ResearchExperiment;
use App\Models\ThesisExperimentAttempt;
use App\Models\User;
use App\Research\CreateResearchExperiment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ExperimentController extends Controller
{
    public function index(Project $project, ManageResearchDataset $datasets): Response
    {
        $this->authorizeProject($project);
        $experiments = ResearchExperiment::query()->where('project_id', $project->id)->latest('id')->get()
            ->map(fn (ResearchExperiment $experiment): array => $this->summary($experiment));

        return Inertia::render('projects/research/experiments/index', [
            'project' => $project->only(['id', 'name']), 'experiments' => $experiments,
            'datasets' => ResearchDataset::query()->where('project_id', $project->id)->where('status', 'frozen')
                ->orderBy('id')->get()->map(fn (ResearchDataset $dataset): array => [
                    'id' => $dataset->id, 'dataset_id' => $dataset->dataset_id, 'version' => $dataset->dataset_version,
                    'sha256' => $dataset->frozen_sha256, 'revision' => $dataset->revision->identifier,
                    'fingerprint' => $datasets->fingerprint($dataset->revision),
                    'questions' => count($dataset->questionRows()),
                ]),
            'configuration' => ['protocol' => config('thesis.protocol_version'), 'models' => config('thesis.models'),
                'methods' => config('thesis.methods')],
            'endpoints' => ['store' => route('projects.research.experiments.store', $project),
                'show' => route('projects.research.experiments.show', [$project, '__EXPERIMENT__'])],
        ]);
    }

    public function store(StoreResearchExperimentRequest $request, Project $project, CreateResearchExperiment $create): RedirectResponse
    {
        $this->authorizeProject($project);
        $dataset = ResearchDataset::query()->findOrFail($request->integer('dataset_id'));
        abort_unless((int) $dataset->project_id === (int) $project->id, 404);
        $owner = $request->user();
        assert($owner instanceof User);
        $experiment = $create->handle($project, $dataset, $owner, $request->integer('repetitions'));
        PrepareResearchExperimentJob::dispatch($experiment->id);

        return to_route('projects.research.experiments.show', [$project, $experiment]);
    }

    public function show(Project $project, ResearchExperiment $experiment): Response
    {
        $this->authorizeExperiment($project, $experiment);
        $heartbeat = $experiment->getAttribute('heartbeat_at');
        if ($experiment->status === 'running' && is_string($heartbeat) && Carbon::parse($heartbeat)->lt(now()->subMinutes(27))) {
            $experiment->forceFill(['status' => 'interrupted', 'safe_error' => __('ui.research.experiment_interrupted'), 'finished_at' => now()])->save();
            $experiment->refresh();
        }

        return Inertia::render('projects/research/experiments/show', [
            'project' => $project->only(['id', 'name']), 'experiment' => $this->summary($experiment),
            'failures' => $experiment->experiment_identity === null ? [] : ThesisExperimentAttempt::query()
                ->where('project_id', $project->id)->where('experiment_key', $experiment->experiment_identity)
                ->whereIn('status', ['failed', 'incomplete'])->latest('id')->limit(50)
                ->get(['id', 'question_id', 'requested_model', 'method', 'repetition_index', 'status'])
                ->map(fn (ThesisExperimentAttempt $attempt): array => $attempt->only(['id', 'question_id', 'requested_model', 'method', 'repetition_index', 'status'])),
            'configuration' => ['protocol' => config('thesis.protocol_version'), 'models' => config('thesis.models'), 'methods' => config('thesis.methods')],
            'endpoints' => ['index' => route('projects.research.experiments.index', $project),
                'start' => route('projects.research.experiments.start', [$project, $experiment]),
                'resume' => route('projects.research.experiments.resume', [$project, $experiment])],
        ]);
    }

    public function start(Project $project, ResearchExperiment $experiment): RedirectResponse
    {
        $this->authorizeExperiment($project, $experiment);
        $identity = DB::transaction(function () use ($experiment): string {
            $locked = ResearchExperiment::query()->lockForUpdate()->findOrFail($experiment->id);
            if ($locked->status !== 'ready' || ! $locked->experiment_identity) {
                throw ValidationException::withMessages(['experiment' => __('ui.research.experiment_not_ready')]);
            }
            $identity = (string) Str::uuid();
            $locked->forceFill(['status' => 'queued', 'execution_identity' => $identity, 'batch_number' => 1,
                'started_at' => now(), 'heartbeat_at' => now(), 'finished_at' => null])->save();

            return $identity;
        });
        RunResearchExperimentBatchJob::dispatch($experiment->id, $identity, 1)->onConnection('redis')->onQueue('research');

        return back();
    }

    public function resume(Project $project, ResearchExperiment $experiment): RedirectResponse
    {
        $this->authorizeExperiment($project, $experiment);
        $identity = DB::transaction(function () use ($experiment): string {
            $locked = ResearchExperiment::query()->lockForUpdate()->findOrFail($experiment->id);
            if (! in_array($locked->status, ['partially_completed', 'interrupted'], true) || $locked->manual_retry_rounds >= 2) {
                throw ValidationException::withMessages(['experiment' => __('ui.research.experiment_not_resumable')]);
            }
            $identity = (string) Str::uuid();
            $locked->forceFill(['status' => 'queued', 'execution_identity' => $identity, 'batch_number' => 1,
                'manual_retry_rounds' => $locked->manual_retry_rounds + 1, 'heartbeat_at' => now(), 'finished_at' => null])->save();

            return $identity;
        });
        RunResearchExperimentBatchJob::dispatch($experiment->id, $identity, 1, retryFailed: true)->onConnection('redis')->onQueue('research');

        return back();
    }

    private function authorizeProject(Project $project): void
    {
        Gate::authorize('view', $project);
    }

    private function authorizeExperiment(Project $project, ResearchExperiment $experiment): void
    {
        $this->authorizeProject($project);
        abort_unless((int) $experiment->project_id === (int) $project->id, 404);
    }

    /** @return array<string, mixed> */
    private function summary(ResearchExperiment $experiment): array
    {
        return [...$experiment->only(['id', 'status', 'repetitions', 'total_cases', 'preparation_total', 'preparation_completed',
            'attempted_cases', 'completed_cases', 'failed_cases', 'incomplete_cases', 'pending_cases', 'skipped_cases', 'safe_error',
            'started_at', 'heartbeat_at', 'finished_at', 'dataset_sha256', 'revision_fingerprints']),
            'dataset' => ['id' => $experiment->dataset->id, 'name' => $experiment->dataset->dataset_id,
                'version' => $experiment->dataset->dataset_version, 'revision' => $experiment->dataset->revision->identifier]];
    }
}
