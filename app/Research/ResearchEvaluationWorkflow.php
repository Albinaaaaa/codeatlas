<?php

namespace App\Research;

use App\Models\ResearchDataset;
use App\Models\ResearchExperiment;
use App\Models\ThesisAnswerEvaluation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

final class ResearchEvaluationWorkflow
{
    public function __construct(private readonly ManualEvaluation $evaluation, private readonly ExportResults $results) {}

    /** @return array{progress: array<string, mixed>, answers: list<array<string, mixed>>, summary: ?array<string, mixed>, locked: bool} */
    public function evaluationPage(ResearchExperiment $experiment): array
    {
        $state = $this->state($experiment);
        $canReview = $this->settled($experiment) && $experiment->getAttribute('results_unlocked_at') === null;

        return [...$state, 'answers' => $canReview ? $state['answers'] : [],
            'locked' => ! $canReview || $state['progress']['evaluated'] < $state['progress']['eligible']];
    }

    /** @return array{progress: array<string, mixed>, answers: list<array<string, mixed>>, summary: ?array<string, mixed>, locked: bool} */
    public function resultsPage(ResearchExperiment $experiment): array
    {
        $state = $this->state($experiment);
        if (! $state['locked']) {
            $this->unlock($experiment, $state['summary']['canonical_attempt_ids']);
            $experiment->refresh();
            $state = $this->state($experiment);
        }
        $locked = $state['locked'] || $experiment->getAttribute('results_unlocked_at') === null;
        $summary = $locked ? null : $state['summary'];
        if ($summary !== null) {
            unset($summary['canonical_attempt_ids'], $summary['model_provenance']);
        }

        return [...$state, 'answers' => [], 'summary' => $summary, 'locked' => $locked];
    }

    /** @param list<bool> $factCoverage
     * @return array{saved: bool, already_present: bool}
     */
    public function save(ResearchExperiment $experiment, string $evaluationId, int $correctness,
        array $factCoverage, int $reviewerId, string $notes = ''): array
    {
        $state = $this->state($experiment);
        if (! $this->settled($experiment) || $experiment->getAttribute('results_unlocked_at') !== null
            || ! in_array($evaluationId, $state['summary']['canonical_attempt_ids'] ?? [], true)) {
            throw new InvalidArgumentException('This answer is not open for manual evaluation.');
        }

        return DB::transaction(function () use ($experiment, $state, $evaluationId, $correctness, $factCoverage, $reviewerId, $notes): array {
            $locked = ResearchExperiment::query()->lockForUpdate()->findOrFail($experiment->id);
            if (! $this->settled($locked) || $locked->getAttribute('results_unlocked_at') !== null) {
                throw new InvalidArgumentException('Results have already been unlocked or the experiment resumed.');
            }

            return $this->evaluation->save($locked->experiment_identity, $state['summary']['canonical_attempt_ids'],
                $evaluationId, $correctness, $factCoverage, $reviewerId, $notes);
        });
    }

    /** @return array{experiment_id: string, attempts: int, summary_groups: int, bundle: string, files: array<string, string>} */
    public function export(ResearchExperiment $experiment): array
    {
        $state = $this->resultsPage($experiment);
        if ($state['locked'] || $experiment->getAttribute('results_unlocked_at') === null) {
            throw new InvalidArgumentException('Results are locked until all eligible answers are evaluated.');
        }

        return $this->results->export($experiment->experiment_identity, $this->datasetPath($experiment), $experiment->repetitions);
    }

    /** @param list<string> $eligibleIds */
    private function unlock(ResearchExperiment $experiment, array $eligibleIds): void
    {
        DB::transaction(function () use ($experiment, $eligibleIds): void {
            $locked = ResearchExperiment::query()->lockForUpdate()->findOrFail($experiment->id);
            if ($locked->getAttribute('results_unlocked_at') !== null) {
                return;
            }
            if (! $this->settled($locked) || $locked->experiment_identity === null) {
                return;
            }
            $path = $this->datasetPath($locked);
            $currentEligibleIds = $this->results->canonicalSuccessfulAttemptIds($locked->experiment_identity, $path, $locked->repetitions);
            $graded = $currentEligibleIds === [] ? 0 : ThesisAnswerEvaluation::query()->whereIn('evaluation_id', $currentEligibleIds)->count();
            if ($currentEligibleIds !== $eligibleIds || $graded !== count($currentEligibleIds)) {
                return;
            }
            $locked->forceFill(['results_unlocked_at' => now()])->save();
        });
    }

    /** @return array{progress: array<string, mixed>, answers: list<array<string, mixed>>, summary: ?array<string, mixed>, locked: bool} */
    private function state(ResearchExperiment $experiment): array
    {
        $empty = ['expected' => $experiment->total_cases, 'successful' => $experiment->completed_cases,
            'failed' => $experiment->failed_cases, 'incomplete' => $experiment->incomplete_cases,
            'missing' => 0, 'pending' => $experiment->pending_cases, 'eligible' => 0, 'evaluated' => 0];
        $identity = $experiment->experiment_identity;
        if (! is_string($identity) || ! preg_match('/\A[a-f0-9]{64}\z/', $identity)) {
            return ['progress' => $empty, 'answers' => [], 'summary' => null, 'locked' => true];
        }
        $path = $this->datasetPath($experiment);
        $summary = $this->results->summaryReport($identity, $path, $experiment->repetitions);
        $groups = $summary['groups'];
        $progress = [
            'expected' => array_sum(array_column($groups, 'expected_cases')),
            'successful' => array_sum(array_column($groups, 'successful_cases')),
            'failed' => array_sum(array_column($groups, 'failed_cases')),
            'incomplete' => array_sum(array_column($groups, 'incomplete_cases')),
            'missing' => array_sum(array_column($groups, 'missing_cases')),
            'pending' => $experiment->pending_cases,
            'eligible' => count($summary['canonical_attempt_ids']),
        ];
        $seed = (int) hexdec(substr($identity, 0, 7));
        $answers = $this->evaluation->blindAnswers($identity, $seed, $summary['canonical_attempt_ids']);
        $progress['evaluated'] = count(array_filter($answers, fn (array $answer): bool => $answer['correctness'] !== null));
        $settled = $this->settled($experiment);

        return ['progress' => $progress, 'answers' => $answers, 'summary' => $summary,
            'locked' => ! $settled || $progress['evaluated'] < $progress['eligible']];
    }

    private function datasetPath(ResearchExperiment $experiment): string
    {
        $dataset = ResearchDataset::query()->find($experiment->research_dataset_id);
        if ($dataset === null || (int) $dataset->project_id !== (int) $experiment->project_id
            || $dataset->status !== 'frozen' || ! is_string($dataset->frozen_path) || ! is_string($dataset->frozen_sha256)) {
            throw new InvalidArgumentException('Frozen research dataset is unavailable.');
        }
        $disk = Storage::disk('thesis_research');
        if (! $disk->exists($dataset->frozen_path)) {
            throw new InvalidArgumentException('Frozen research dataset is unavailable.');
        }
        $raw = $disk->get($dataset->frozen_path);
        if (! hash_equals($experiment->dataset_sha256, hash('sha256', $raw))) {
            throw new InvalidArgumentException('Frozen research dataset integrity check failed.');
        }

        return $disk->path($dataset->frozen_path);
    }

    private function settled(ResearchExperiment $experiment): bool
    {
        return in_array($experiment->status, ['completed', 'partially_completed', 'failed', 'interrupted'], true);
    }
}
