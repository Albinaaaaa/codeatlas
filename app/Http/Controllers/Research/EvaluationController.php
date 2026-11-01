<?php

namespace App\Http\Controllers\Research;

use App\Http\Controllers\Controller;
use App\Http\Requests\Research\StoreEvaluationRequest;
use App\Models\Project;
use App\Models\ResearchExperiment;
use App\Models\User;
use App\Research\ResearchEvaluationWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class EvaluationController extends Controller
{
    public function show(Project $project, ResearchExperiment $experiment, ResearchEvaluationWorkflow $workflow): Response
    {
        $this->authorizeExperiment($project, $experiment);
        try {
            $state = $workflow->evaluationPage($experiment);
            $safeError = null;
        } catch (InvalidArgumentException) {
            $state = ['locked' => true, 'answers' => [], 'progress' => $this->fallbackProgress($experiment)];
            $safeError = __('ui.research.evaluation_unavailable');
        }

        return Inertia::render('projects/research/experiments/evaluate', [
            'project' => $project->only(['id', 'name']),
            'experiment' => ['id' => $experiment->id, 'status' => $experiment->status],
            'locked' => $state['locked'], 'progress' => $state['progress'], 'answers' => $state['answers'], 'safe_error' => $safeError,
            'endpoints' => ['store' => route('projects.research.experiments.evaluation.store', [$project, $experiment]),
                'results' => route('projects.research.experiments.results', [$project, $experiment])],
        ]);
    }

    public function store(StoreEvaluationRequest $request, Project $project, ResearchExperiment $experiment,
        ResearchEvaluationWorkflow $workflow): RedirectResponse
    {
        $this->authorizeExperiment($project, $experiment);
        $user = $request->user();
        assert($user instanceof User);
        $data = $request->validated();
        try {
            $workflow->save($experiment, $data['evaluation_id'], (int) $data['correctness'], $data['fact_coverage'],
                (int) $user->id, $data['notes'] ?? '');
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['evaluation_id' => __('ui.research.evaluation_conflict')]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('ui.research.evaluation_saved')]);

        return back();
    }

    /** @return array<string, int> */
    private function fallbackProgress(ResearchExperiment $experiment): array
    {
        return ['expected' => $experiment->total_cases, 'successful' => $experiment->completed_cases,
            'failed' => $experiment->failed_cases, 'incomplete' => $experiment->incomplete_cases,
            'missing' => 0, 'pending' => $experiment->pending_cases, 'eligible' => 0, 'evaluated' => 0];
    }

    private function authorizeExperiment(Project $project, ResearchExperiment $experiment): void
    {
        Gate::authorize('view', $project);
        abort_unless((int) $experiment->project_id === (int) $project->id, 404);
    }
}
