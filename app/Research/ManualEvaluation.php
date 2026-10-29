<?php

namespace App\Research;

use App\Models\ThesisAnswerEvaluation;
use App\Models\ThesisExperimentAttempt;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;

final class ManualEvaluation
{
    public const RUBRIC = '1.0.0';

    public function __construct(private readonly ResearchFiles $files) {}

    public function identifier(ThesisExperimentAttempt $attempt): string
    {
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new InvalidArgumentException('An application encryption key is required for blind identifiers.');
        }

        return hash_hmac('sha256', 'thesis-blind:'.self::RUBRIC.':'.$attempt->attempt_id, $key);
    }

    /** @return Collection<int, ThesisExperimentAttempt> */
    public function attempts(string $experiment): Collection
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/', $experiment)) {
            throw new InvalidArgumentException('Experiment identifier must be a SHA-256 hash.');
        }

        return ThesisExperimentAttempt::query()->where('experiment_key', $experiment)->orderBy('id')->get();
    }

    /** @return array<string, mixed> */
    public function export(string $experiment, int $seed): array
    {
        $attempts = $this->attempts($experiment);
        if ($attempts->isEmpty()) {
            throw new InvalidArgumentException('Experiment has no stored attempts.');
        }
        $rows = [];
        foreach ($attempts as $attempt) {
            if ($attempt->status !== 'successful') {
                continue;
            }
            $details = $attempt->details();
            $rows[] = ['evaluation_id' => $this->identifier($attempt), 'rubric_version' => self::RUBRIC,
                'question' => $details['dataset_question']['question'], 'reference_answer' => $details['dataset_question']['reference_answer'],
                'required_facts' => $details['dataset_question']['required_facts'], 'answer' => $details['inference']['answer']];
        }
        // Only opaque identifiers influence the ordering; no case order or grouping is exposed.
        usort($rows, fn (array $a, array $b): int => strcmp(hash('sha256', $seed.':'.$a['evaluation_id']), hash('sha256', $seed.':'.$b['evaluation_id'])));
        $raw = implode("\n", array_map(fn (array $row): string => json_encode($row, ResearchFiles::JSON_FLAGS), $rows));

        return ['exported' => count($rows), 'excluded_non_successful' => $attempts->count() - count($rows),
            'files' => $this->files->write($experiment, 'grading', ['answers.jsonl' => $raw === '' ? '' : $raw."\n",
                'rubric.json' => json_encode(['rubric_version' => self::RUBRIC, 'correctness' => [
                    0 => 'Incorrect: core conclusion is wrong or unsupported.',
                    1 => 'Partially correct: some supported content, but material omissions or errors.',
                    2 => 'Correct: covers required facts without material errors or unsupported claims.',
                ], 'fact_coverage' => 'One manual boolean judgment per required fact, in the supplied order.',
                    'notes' => 'Optional missing facts or unsupported claims. Scores are manual, not inferred from word overlap.'], ResearchFiles::JSON_FLAGS | JSON_PRETTY_PRINT)."\n"])];
    }

    /** @return array{imported: int, already_present: int} */
    public function import(string $experiment, string $path): array
    {
        $attempts = $this->attempts($experiment)->where('status', 'successful');
        $mapping = [];
        foreach ($attempts as $attempt) {
            $mapping[$this->identifier($attempt)] = $attempt;
        }
        $resolved = realpath($path);
        if ($resolved === false || ! is_file($resolved) || ! is_readable($resolved)) {
            throw new InvalidArgumentException('Grades must be a readable local JSONL file.');
        }
        $stream = fopen($resolved, 'rb');
        if ($stream === false) {
            throw new InvalidArgumentException('Grades could not be opened.');
        }
        $pending = [];
        $line = 0;
        try {
            while (($raw = fgets($stream)) !== false) {
                $line++;
                try {
                    $row = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw new InvalidArgumentException('Invalid grade JSON at line '.$line.'.');
                }
                $allowed = ['evaluation_id', 'rubric_version', 'correctness', 'fact_coverage', 'reviewer', 'notes'];
                if (! is_array($row) || array_is_list($row) || array_diff(array_keys($row), $allowed) !== []
                    || ! is_string($row['evaluation_id'] ?? null) || ! isset($mapping[$row['evaluation_id']])
                    || ($row['rubric_version'] ?? null) !== self::RUBRIC
                    || ! is_int($row['correctness'] ?? null) || $row['correctness'] < 0 || $row['correctness'] > 2
                    || ! is_array($row['fact_coverage'] ?? null) || ! array_is_list($row['fact_coverage'])
                    || ! is_string($row['reviewer'] ?? null) || trim($row['reviewer']) === '' || mb_strlen($row['reviewer']) > 128
                    || (array_key_exists('notes', $row) && (! is_string($row['notes']) || mb_strlen($row['notes']) > 8000))) {
                    throw new InvalidArgumentException('Invalid grade fields, identifier or rubric at line '.$line.'.');
                }
                $attempt = $mapping[$row['evaluation_id']];
                $facts = $attempt->details()['dataset_question']['required_facts'];
                if (count($row['fact_coverage']) !== count($facts) || $facts === []
                    || array_filter($row['fact_coverage'], fn (mixed $value): bool => ! is_bool($value)) !== []) {
                    throw new InvalidArgumentException('Every required fact needs one boolean judgment at line '.$line.'.');
                }
                if (isset($pending[$row['evaluation_id']])) {
                    throw new InvalidArgumentException('Duplicate evaluation identifier at line '.$line.'.');
                }
                $pending[$row['evaluation_id']] = ['attempt' => $attempt, 'correctness' => $row['correctness'],
                    'payload' => ['fact_coverage' => $row['fact_coverage'], 'reviewer' => $row['reviewer'], 'notes' => $row['notes'] ?? '']];
            }
            if (! feof($stream) || $pending === []) {
                throw new InvalidArgumentException('Grades file is empty or could not be read completely.');
            }
        } finally {
            fclose($stream);
        }
        try {
            return DB::transaction(function () use ($pending): array {
                $imported = 0;
                $existing = 0;
                foreach ($pending as $id => $grade) {
                    $saved = ThesisAnswerEvaluation::query()->where('thesis_experiment_attempt_id', $grade['attempt']->id)->lockForUpdate()->first();
                    if ($saved !== null) {
                        if ($saved->evaluation_id !== $id || $saved->rubric_version !== self::RUBRIC
                            || $saved->correctness !== $grade['correctness'] || $saved->details() !== $grade['payload']) {
                            throw new InvalidArgumentException('Conflicting evaluation exists; no grades were overwritten.');
                        }
                        $existing++;

                        continue;
                    }
                    ThesisAnswerEvaluation::forceCreate(['thesis_experiment_attempt_id' => $grade['attempt']->id,
                        'evaluation_id' => $id, 'rubric_version' => self::RUBRIC, 'correctness' => $grade['correctness'], 'payload' => $grade['payload']]);
                    $imported++;
                }

                return ['imported' => $imported, 'already_present' => $existing];
            });
        } catch (UniqueConstraintViolationException) {
            throw new InvalidArgumentException('Concurrent or duplicate grade import rejected; retry after reviewing existing evaluations.');
        }
    }
}
