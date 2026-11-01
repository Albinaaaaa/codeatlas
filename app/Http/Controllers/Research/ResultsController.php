<?php

namespace App\Http\Controllers\Research;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ResearchExperiment;
use App\Research\ResearchEvaluationWorkflow;
use App\Research\ResearchFiles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ResultsController extends Controller
{
    public function show(Request $request, Project $project, ResearchExperiment $experiment,
        ResearchEvaluationWorkflow $workflow, ResearchFiles $files): Response
    {
        $this->authorizeExperiment($project, $experiment);
        try {
            $state = $workflow->resultsPage($experiment);
            $safeError = null;
        } catch (InvalidArgumentException) {
            $state = ['locked' => true, 'progress' => $this->fallbackProgress($experiment), 'summary' => null];
            $safeError = __('ui.research.results_unavailable');
        }
        $bundle = $request->query('bundle');
        $downloadFiles = [];
        if (is_string($bundle) && $bundle !== '' && ! $state['locked']) {
            try {
                $downloadFiles = $files->bundleFiles((string) $experiment->experiment_identity, 'results', $bundle);
            } catch (InvalidArgumentException) {
                abort(404);
            }
        }

        return Inertia::render('projects/research/experiments/results', [
            'project' => $project->only(['id', 'name']), 'experiment' => ['id' => $experiment->id, 'status' => $experiment->status],
            'locked' => $state['locked'], 'progress' => $state['progress'], 'summary' => $state['summary'], 'safe_error' => $safeError,
            'bundle' => $downloadFiles === [] ? null : $bundle, 'files' => array_map(fn (string $file): array => [
                'name' => $file, 'url' => route('projects.research.experiments.exports.download', [$project, $experiment, $bundle, $file]),
            ], $downloadFiles),
            'endpoints' => ['export' => route('projects.research.experiments.exports.store', [$project, $experiment]),
                'evaluation' => route('projects.research.experiments.evaluation', [$project, $experiment])],
        ]);
    }

    public function export(Project $project, ResearchExperiment $experiment, ResearchEvaluationWorkflow $workflow): RedirectResponse
    {
        $this->authorizeExperiment($project, $experiment);
        try {
            $bundle = $workflow->export($experiment)['bundle'];
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['results' => __('ui.research.results_locked')]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('ui.research.export_created')]);

        return to_route('projects.research.experiments.results', [$project, $experiment, 'bundle' => $bundle]);
    }

    public function download(Project $project, ResearchExperiment $experiment, string $bundle, string $file,
        ResearchEvaluationWorkflow $workflow, ResearchFiles $files): BinaryFileResponse
    {
        $this->authorizeExperiment($project, $experiment);
        try {
            $state = $workflow->resultsPage($experiment);
            if ($state['locked'] || $experiment->getAttribute('results_unlocked_at') === null) {
                abort(404);
            }
            $path = $files->downloadPath((string) $experiment->experiment_identity, 'results', $bundle, $file);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        $download = response()->download($path, $file);
        $download->setPrivate();
        $download->headers->set('Cache-Control', 'private, no-store');

        return $download;
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
