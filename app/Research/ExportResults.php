<?php

namespace App\Research;

use App\Models\ThesisAnswerEvaluation;
use App\Models\ThesisExperimentAttempt;
use InvalidArgumentException;

final class ExportResults
{
    public function __construct(private readonly ManualEvaluation $evaluation, private readonly ResearchFiles $files,
        private readonly DatasetValidator $validator, private readonly RunExperiments $runner, private readonly RecordExperiment $identity) {}

    /** @return array<string, mixed> */
    public function export(string $experiment, ?string $datasetPath = null, ?int $repetitions = null,
        bool $byCategory = false, bool $rawOnly = false): array
    {
        $attempts = $this->evaluation->attempts($experiment);
        $grades = ThesisAnswerEvaluation::query()->whereIn('thesis_experiment_attempt_id', $attempts->modelKeys())->get()->keyBy('thesis_experiment_attempt_id');
        $entries = [];
        $raw = [];
        $csv = [];
        foreach ($attempts as $attempt) {
            $details = $attempt->details();
            $grade = $grades->get($attempt->id);
            $entries[] = ['attempt' => $attempt, 'details' => $details, 'grade' => $grade];
            $raw[] = ['attributes' => $attempt->getRawOriginal(), 'details' => $details,
                'evaluation' => $grade === null ? null : ['attributes' => $grade->getRawOriginal(), 'details' => $grade->details()]];
            $csv[] = $this->flat($attempt, $details, $grade);
        }
        $summary = null;
        if (! $rawOnly) {
            if ($datasetPath === null) {
                throw new InvalidArgumentException('A frozen dataset path is required for completeness summaries.');
            }
            $summary = $this->summary($experiment, $datasetPath, $entries, $repetitions, $byCategory);
        } elseif ($attempts->isEmpty()) {
            throw new InvalidArgumentException('Experiment has no stored attempts.');
        }
        $envelope = ['format_version' => '1.0.0', 'experiment_id' => $experiment, 'attempt_count' => count($raw), 'records' => $raw];
        $jsonl = implode("\n", array_map(fn (array $record): string => json_encode(['format_version' => '1.0.0', 'experiment_id' => $experiment, 'record' => $record], ResearchFiles::JSON_FLAGS), $raw));
        $outputs = ['raw.json' => json_encode($envelope, ResearchFiles::JSON_FLAGS | JSON_PRETTY_PRINT)."\n",
            'raw.jsonl' => $jsonl === '' ? '' : $jsonl."\n", 'attempts.csv' => $this->files->csv($csv)];
        if ($summary !== null) {
            $outputs['summary.json'] = json_encode($summary, ResearchFiles::JSON_FLAGS | JSON_PRETTY_PRINT)."\n";
            $summaryRows = [];
            foreach ($summary['groups'] as $group) {
                $row = $group;
                unset($row['metrics']);
                foreach ($group['metrics'] as $name => $metric) {
                    $row[$name.'_mean'] = $metric['mean'];
                    $row[$name.'_n'] = $metric['n'];
                    $row[$name.'_unit'] = $metric['unit'];
                }
                $summaryRows[] = $row;
            }
            $outputs['summary.csv'] = $this->files->csv($summaryRows);
        }

        return ['experiment_id' => $experiment, 'attempts' => count($raw), 'summary_groups' => $summary === null ? 0 : count($summary['groups']),
            'files' => $this->files->write($experiment, 'results', $outputs)];
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function flat(ThesisExperimentAttempt $attempt, array $details, ?ThesisAnswerEvaluation $grade): array
    {
        $inference = $details['inference'];
        $coverage = $grade?->details()['fact_coverage'] ?? null;

        return ['experiment_id' => $attempt->experiment_key, 'case_id' => $attempt->case_key, 'run_key' => $attempt->run_key,
            'attempt_id' => $attempt->attempt_id, 'question_id' => $attempt->question_id, 'category' => $details['dataset_question']['category'],
            'revision_fingerprint' => $attempt->revision_fingerprint, 'model' => $attempt->requested_model, 'returned_model' => $attempt->returned_model,
            'method' => $attempt->method, 'repetition_index' => $attempt->repetition_index, 'status' => $attempt->status,
            'protocol_version' => $attempt->protocol_version, 'dataset_id' => $attempt->dataset_id, 'dataset_version' => $attempt->dataset_version,
            'dataset_sha256' => $attempt->dataset_sha256, 'prompt_version' => $attempt->prompt_version, 'prompt_sha256' => $attempt->prompt_sha256,
            'context_sha256' => $this->identity->identityHash(['context' => $inference['selected_context']]),
            'selected_chunk_ids' => array_column($inference['selected_chunks'], 'chunk_id'),
            'estimated_prompt_tokens' => $inference['estimated_token_usage']['prompt_tokens'], 'estimated_prompt_tokens_is_estimate' => true,
            'estimation_method' => $inference['estimated_token_usage']['counting_method'],
            'actual_input_tokens' => $inference['actual_metrics']['prompt_eval_count'], 'actual_output_tokens' => $inference['actual_metrics']['eval_count'],
            'total_duration_ns' => $inference['actual_metrics']['total_duration'], 'load_duration_ns' => $inference['actual_metrics']['load_duration'],
            'prompt_eval_duration_ns' => $inference['actual_metrics']['prompt_eval_duration'], 'eval_duration_ns' => $inference['actual_metrics']['eval_duration'],
            'inference_wall_ms' => $inference['timings_ms']['inference_wall'], 'end_to_end_ms' => $inference['timings_ms']['total_wall'],
            'completion_status' => $inference['completion']['status'], 'stop_reason' => $inference['completion']['stop_reason'],
            'error' => $inference['error']['message'] ?? null, 'answer' => $inference['answer'], 'rubric_version' => $grade?->rubric_version,
            'correctness' => $grade?->correctness, 'facts_covered' => $coverage === null ? null : count(array_filter($coverage)),
            'facts_total' => $coverage === null ? null : count($coverage),
            'required_fact_coverage' => $coverage === null ? null : count(array_filter($coverage)) / count($coverage)];
    }

    /**
     * @param  list<array{attempt: ThesisExperimentAttempt, details: array<string, mixed>, grade: ?ThesisAnswerEvaluation}>  $entries
     * @return array<string, mixed>
     */
    private function summary(string $experiment, string $path, array $entries, ?int $requestedRepetitions, bool $byCategory): array
    {
        $dataset = $this->validator->validate($path);
        if ($dataset['errors'] !== []) {
            throw new InvalidArgumentException('Summary requires a valid unchanged dataset and immutable snapshots.');
        }
        $first = $entries[0] ?? null;
        $configuration = $first === null ? config('thesis') : $first['details']['inference']['protocol_configuration'];
        $repetitions = $first === null ? ($requestedRepetitions ?? config('thesis.repetitions')) : ($first['details']['execution']['repetitions'] ?? null);
        if (! is_int($repetitions) || $repetitions < 1 || ($requestedRepetitions !== null && $requestedRepetitions !== $repetitions)) {
            throw new InvalidArgumentException('Repetition count must match the frozen runner configuration.');
        }
        if ($first === null && $this->runner->run($path, $repetitions, dryRun: true)['experiment_id'] !== $experiment) {
            throw new InvalidArgumentException('No stored experiment exists and dry-run configuration does not match this identifier.');
        }
        $questions = [];
        foreach ($dataset['questions'] as $question) {
            $questions[$question->id] = $question;
        }
        $histories = [];
        $provenance = [];
        foreach ($entries as $entry) {
            $attempt = $entry['attempt'];
            $question = $questions[$attempt->question_id] ?? null;
            if ($attempt->dataset_sha256 !== $dataset['dataset_sha256'] || $question === null
                || $attempt->revision_fingerprint !== $question->fingerprint || (int) $attempt->project_revision_id !== $question->revisionId
                || $this->identity->identityHash($entry['details']['dataset_question']) !== $this->identity->identityHash($question->evaluationData())
                || $this->identity->identityHash($entry['details']['inference']['protocol_configuration']) !== $this->identity->identityHash($configuration)
                || ($entry['details']['execution']['repetitions'] ?? null) !== $repetitions
                || ($entry['details']['execution']['total_cases'] ?? null) !== 9 * count($questions) * $repetitions
                || ! in_array($attempt->requested_model, $configuration['models'], true) || ! in_array($attempt->method, $configuration['methods'], true)
                || $attempt->repetition_index < 1 || $attempt->repetition_index > $repetitions) {
                throw new InvalidArgumentException('Stored attempts do not match the frozen dataset or execution configuration; raw-only export remains available.');
            }
            if ($entry['grade'] !== null && $entry['grade']->rubric_version !== ManualEvaluation::RUBRIC) {
                throw new InvalidArgumentException('Different rubric versions cannot be averaged together; raw-only export remains available.');
            }
            $provenance[$attempt->requested_model][$this->identity->identityHash($entry['details']['model_provenance'])] = $entry['details']['model_provenance'];
            $key = json_encode([$attempt->question_id, $attempt->requested_model, $attempt->method, $attempt->repetition_index], JSON_THROW_ON_ERROR);
            $histories[$key][] = $entry;
        }
        foreach ($provenance as $observations) {
            if (count($observations) > 1) {
                throw new InvalidArgumentException('Mixed model provenance cannot be averaged as one experiment; use raw-only export and separate campaigns.');
            }
        }
        $categories = $byCategory ? array_values(array_unique(array_map(fn (DatasetQuestion $q): string => $q->evaluationData()['category'], $dataset['questions']))) : [null];
        sort($categories);
        $groups = [];
        $modelObservations = [];
        foreach ($configuration['models'] as $model) {
            $modelObservations[$model] = isset($provenance[$model]) ? array_values($provenance[$model])[0] : null;
            foreach ($configuration['methods'] as $method) {
                foreach ($categories as $category) {
                    $group = ['model' => $model, 'method' => $method, 'category' => $category, 'expected_cases' => 0,
                        'successful_cases' => 0, 'failed_cases' => 0, 'incomplete_cases' => 0, 'missing_cases' => 0,
                        'successful_attempts' => 0, 'failed_attempts' => 0, 'incomplete_attempts' => 0, 'graded_attempts' => 0,
                        'graded_cases' => 0, 'facts_covered' => 0, 'facts_total' => 0];
                    $values = [];
                    foreach ($questions as $question) {
                        if ($category !== null && $question->evaluationData()['category'] !== $category) {
                            continue;
                        }
                        for ($rep = 1; $rep <= $repetitions; $rep++) {
                            $group['expected_cases']++;
                            $history = $histories[json_encode([$question->id, $model, $method, $rep], JSON_THROW_ON_ERROR)] ?? [];
                            $chosen = null;
                            foreach ($history as $entry) {
                                $status = $entry['attempt']->status;
                                $group[$status.'_attempts']++;
                                if ($entry['grade'] !== null) {
                                    $group['graded_attempts']++;
                                }
                                // Freeze the earliest successful observation; otherwise retain latest failure/incomplete.
                                if ($chosen === null || $chosen['attempt']->status !== 'successful') {
                                    $chosen = $entry;
                                }
                            }
                            if ($chosen === null) {
                                $group['missing_cases']++;

                                continue;
                            }
                            $group[$chosen['attempt']->status.'_cases']++;
                            if ($chosen['attempt']->status !== 'successful') {
                                continue;
                            }
                            $inference = $chosen['details']['inference'];
                            foreach (['prompt_eval_count', 'eval_count', 'total_duration', 'load_duration', 'prompt_eval_duration', 'eval_duration'] as $name) {
                                $this->sample($values, $name, $inference['actual_metrics'][$name] ?? null);
                            }
                            foreach (['total_wall', 'inference_wall', 'context_construction'] as $name) {
                                $this->sample($values, $name, $inference['timings_ms'][$name] ?? null);
                            }
                            if ($chosen['grade'] !== null) {
                                $group['graded_cases']++;
                                $grade = $chosen['grade'];
                                $coverage = $grade->details()['fact_coverage'];
                                $covered = count(array_filter($coverage));
                                $group['facts_covered'] += $covered;
                                $group['facts_total'] += count($coverage);
                                $this->sample($values, 'correctness', $grade->correctness);
                                $this->sample($values, 'required_fact_coverage', $covered / count($coverage));
                            }
                        }
                    }
                    $group['metrics'] = [];
                    foreach (['correctness' => 'score_0_to_2', 'required_fact_coverage' => 'fraction', 'prompt_eval_count' => 'tokens', 'eval_count' => 'tokens',
                        'total_wall' => 'milliseconds', 'inference_wall' => 'milliseconds', 'context_construction' => 'milliseconds',
                        'total_duration' => 'nanoseconds', 'load_duration' => 'nanoseconds', 'prompt_eval_duration' => 'nanoseconds', 'eval_duration' => 'nanoseconds'] as $name => $unit) {
                        $samples = $values[$name] ?? [];
                        $group['metrics'][$name] = ['mean' => $samples === [] ? null : array_sum($samples) / count($samples), 'n' => count($samples), 'unit' => $unit];
                    }
                    $groups[] = $group;
                }
            }
        }

        return ['format_version' => '1.0.0', 'experiment_id' => $experiment, 'dataset_sha256' => $dataset['dataset_sha256'],
            'protocol_version' => $configuration['protocol_version'], 'rubric_version' => ManualEvaluation::RUBRIC, 'repetitions' => $repetitions,
            'selection_rule' => 'earliest_successful_attempt_else_latest_attempt_per_question_model_method_repetition',
            'metric_population' => 'canonical_successful_cases; correctness_and_coverage_only_manually_graded_canonical_cases',
            'model_provenance' => $modelObservations, 'groups' => $groups];
    }

    /** @param array<string, list<int|float>> $values */
    private function sample(array &$values, string $name, mixed $value): void
    {
        if ((is_int($value) || is_float($value)) && is_finite((float) $value)) {
            $values[$name][] = $value;
        }
    }
}
