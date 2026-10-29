<?php

namespace Tests\Feature\Research;

use App\Models\ThesisAnswerEvaluation;
use App\Models\ThesisExperimentAttempt;
use App\Research\ExportResults;
use App\Research\ManualEvaluation;
use App\Research\ResearchFiles;
use App\Research\RunExperiments;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Concerns\ResearchFixture;
use Tests\RefreshDatabase;
use Tests\TestCase;

class EvaluationExportTest extends TestCase
{
    use RefreshDatabase, ResearchFixture;

    private string $exportRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeResearchStorage();
        $this->exportRoot = storage_path('app/private/test-research-'.Str::uuid());
        config(['thesis' => require base_path('config/thesis.php'), 'services.ollama.url' => 'http://ollama.test:11434',
            'filesystems.disks.thesis_research.root' => $this->exportRoot]);
    }

    protected function tearDown(): void
    {
        if (isset($this->exportRoot) && str_starts_with($this->exportRoot, storage_path('app/private/test-research-'))) {
            File::deleteDirectory($this->exportRoot);
        }
        parent::tearDown();
    }

    public function test_end_to_end_blind_grades_and_exports_preserve_original_records(): void
    {
        $fixture = $this->researchFixture();
        $this->fakeOllama();
        $report = app(RunExperiments::class)->run($fixture['path'], 1);
        $experiment = $report['experiment_id'];
        $before = DB::table('thesis_experiment_attempts')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        $httpCount = count(Http::recorded());
        $this->assertSame(0, Artisan::call('thesis:grade:export', ['experimentId' => $experiment, '--seed' => 123]));
        $export = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $blind = $this->jsonl($export['files']['answers.jsonl']);
        $this->assertCount(9, $blind);
        $this->assertSame(0, $export['excluded_non_successful']);
        foreach ($blind as $row) {
            $this->assertSame(['evaluation_id', 'rubric_version', 'question', 'reference_answer', 'required_facts', 'answer'], array_keys($row));
            $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $row['evaluation_id']);
            $this->assertSame($fixture['row']['reference_answer'], $row['reference_answer']);
        }
        $rawBlind = file_get_contents($export['files']['answers.jsonl']);
        foreach ([...config('thesis.models'), ...config('thesis.methods'), 'prompt_sha256', 'attempt_id', 'repetition_index', 'temperature'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $rawBlind);
        }
        $again = app(ManualEvaluation::class)->export($experiment, 123);
        $this->assertSame($rawBlind, file_get_contents($again['files']['answers.jsonl']));
        $different = app(ManualEvaluation::class)->export($experiment, 456);
        $this->assertNotSame(array_column($blind, 'evaluation_id'), array_column($this->jsonl($different['files']['answers.jsonl']), 'evaluation_id'));
        $attempt = ThesisExperimentAttempt::query()->firstOrFail();
        $id = app(ManualEvaluation::class)->identifier($attempt);
        $this->assertSame($id, app(ManualEvaluation::class)->identifier($attempt->fresh()));
        $this->assertNotSame($attempt->case_key, $id);
        $this->assertNotSame(hash('sha256', $attempt->attempt_id), $id);
        $grades = array_map(fn (array $row): array => $this->grade($row['evaluation_id']), $blind);
        $path = $this->datasetFile($grades);
        $this->assertSame(0, Artisan::call('thesis:grade:import', ['experimentId' => $experiment, 'path' => $path]));
        $this->assertSame(9, json_decode(Artisan::output(), true)['imported']);
        $this->assertSame(0, Artisan::call('thesis:results:export', ['experimentId' => $experiment, 'datasetPath' => $fixture['path']]));
        $results = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $json = json_decode(file_get_contents($results['files']['raw.json']), true, flags: JSON_THROW_ON_ERROR);
        $lines = $this->jsonl($results['files']['raw.jsonl']);
        $this->assertCount(9, $json['records']);
        $this->assertSame($json['records'][0], $lines[0]['record']);
        $this->assertSame($attempt->details(), $json['records'][0]['details']);
        $this->assertSame($attempt->getRawOriginal(), $json['records'][0]['attributes']);
        $csv = $this->csv($results['files']['attempts.csv']);
        $this->assertCount(9, $csv);
        $this->assertSame("Відповідь: \"42\".\nЦіле число.", $csv[0]['answer']);
        $this->assertSame('100', $csv[0]['actual_input_tokens']);
        $this->assertSame('true', $csv[0]['estimated_prompt_tokens_is_estimate']);
        $this->assertSame('2', $csv[0]['correctness']);
        $summary = json_decode(file_get_contents($results['files']['summary.json']), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(9, $summary['groups']);
        foreach ($summary['groups'] as $group) {
            $this->assertSame([1, 1, 1, 0], [$group['expected_cases'], $group['successful_cases'], $group['graded_cases'], $group['missing_cases']]);
            $this->assertEquals(2, $group['metrics']['correctness']['mean']);
            $this->assertSame(1, $group['metrics']['correctness']['n']);
            $this->assertEquals(1, $group['metrics']['required_fact_coverage']['mean']);
        }
        $this->assertSame($before, DB::table('thesis_experiment_attempts')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all());
        $this->assertSame($httpCount, count(Http::recorded()), 'Grading/export must never call inference.');
        $saved = ThesisAnswerEvaluation::query()->firstOrFail();
        $this->assertArrayNotHasKey('payload', $saved->toArray());
        $this->assertStringNotContainsString('рецензент', DB::table('thesis_answer_evaluations')->value('payload'));
        foreach ($results['files'] as $file) {
            $this->assertStringStartsWith($this->exportRoot.'/', $file);
            $this->assertSame(0, fileperms($file) & 0077);
        }
    }

    public function test_invalid_grades_duplicate_ids_and_conflicts_are_atomic_and_never_overwrite(): void
    {
        $fixture = $this->researchFixture();
        $this->fakeOllama();
        $report = app(RunExperiments::class)->run($fixture['path'], 1, limit: 2);
        $attempts = ThesisExperimentAttempt::query()->orderBy('id')->get();
        $service = app(ManualEvaluation::class);
        $valid = $this->grade($service->identifier($attempts[0]));
        foreach ([['correctness' => -1], ['correctness' => 3], ['correctness' => '2'], ['correctness' => 1.5],
            ['evaluation_id' => str_repeat('f', 64)], ['rubric_version' => '2.0.0'], ['reviewer' => ' '], ['reviewer' => []],
            ['fact_coverage' => []], ['fact_coverage' => [1]], ['fact_coverage' => [true, false]], ['fact_coverage' => ['x' => true]],
            ['notes' => null], ['model' => 'hidden']] as $change) {
            try {
                $service->import($report['experiment_id'], $this->datasetFile([[...$valid, ...$change]]));
                $this->fail('Invalid grade must be rejected.');
            } catch (InvalidArgumentException) {
                $this->assertDatabaseCount('thesis_answer_evaluations', 0);
            }
        }
        try {
            $service->import($report['experiment_id'], $this->datasetFile([$valid, $valid]));
            $this->fail('Duplicate identifiers must be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseCount('thesis_answer_evaluations', 0);
        }
        $path = $this->datasetFile([$valid]);
        $this->assertSame(['imported' => 1, 'already_present' => 0], $service->import($report['experiment_id'], $path));
        $before = DB::table('thesis_answer_evaluations')->get()->map(fn (object $row): array => (array) $row)->all();
        $this->assertSame(['imported' => 0, 'already_present' => 1], $service->import($report['experiment_id'], $path));
        $other = $this->grade($service->identifier($attempts[1]));
        $conflict = [...$valid, 'correctness' => 0];
        $this->assertSame(1, Artisan::call('thesis:grade:import', ['experimentId' => $report['experiment_id'], 'path' => $this->datasetFile([$other, $conflict])]));
        $this->assertSame($before, DB::table('thesis_answer_evaluations')->get()->map(fn (object $row): array => (array) $row)->all());
        foreach (["not JSON\n", '', "[]\n"] as $raw) {
            file_put_contents($path, $raw);
            $this->assertSame(1, Artisan::call('thesis:grade:import', ['experimentId' => $report['experiment_id'], 'path' => $path]));
        }
    }

    public function test_failed_and_incomplete_attempts_stay_visible_and_are_not_graded_as_zero(): void
    {
        $fixture = $this->researchFixture();
        $this->fakeOllama(['failed', 'incomplete']);
        $report = app(RunExperiments::class)->run($fixture['path'], 1);
        $blind = app(ManualEvaluation::class)->export($report['experiment_id'], 42);
        $this->assertSame([7, 2], [$blind['exported'], $blind['excluded_non_successful']]);
        $failed = ThesisExperimentAttempt::query()->where('status', 'failed')->firstOrFail();
        $this->assertSame(1, Artisan::call('thesis:grade:import', ['experimentId' => $report['experiment_id'],
            'path' => $this->datasetFile([$this->grade(app(ManualEvaluation::class)->identifier($failed))])]));
        $export = app(ExportResults::class)->export($report['experiment_id'], $fixture['path']);
        $failedCsv = array_values(array_filter($this->csv($export['files']['attempts.csv']), fn ($row) => $row['status'] === 'failed'))[0];
        $this->assertSame('', $failedCsv['actual_input_tokens']);
        $this->assertSame('', $failedCsv['correctness']);
        $groups = json_decode(file_get_contents($export['files']['summary.json']), true)['groups'];
        $this->assertSame([7, 1, 1, 0], [array_sum(array_column($groups, 'successful_cases')), array_sum(array_column($groups, 'failed_cases')),
            array_sum(array_column($groups, 'incomplete_cases')), array_sum(array_column($groups, 'graded_cases'))]);
        foreach ($groups as $group) {
            $this->assertNull($group['metrics']['correctness']['mean']);
            $this->assertSame(0, $group['metrics']['correctness']['n']);
        }
        $this->fakeOllama();
        app(RunExperiments::class)->run($fixture['path'], 1, resume: true);
        $retry = app(ExportResults::class)->export($report['experiment_id'], $fixture['path']);
        $groups = json_decode(file_get_contents($retry['files']['summary.json']), true)['groups'];
        $this->assertSame([9, 1, 1, 11], [array_sum(array_column($groups, 'successful_cases')), array_sum(array_column($groups, 'failed_attempts')),
            array_sum(array_column($groups, 'incomplete_attempts')), $retry['attempts']]);
    }

    public function test_averages_use_canonical_cases_only_and_explicit_non_missing_denominators(): void
    {
        $fixture = $this->researchFixture();
        $row = $fixture['row'];
        $row['required_facts'][] = 'The declared return type is int.';
        $path = $this->datasetFile([$row]);
        $this->fakeOllama(['missing_metrics']);
        $report = app(RunExperiments::class)->run($path, 2);
        $first = ThesisExperimentAttempt::query()->orderBy('id')->firstOrFail();
        $pair = ThesisExperimentAttempt::query()->where('requested_model', $first->requested_model)->where('method', $first->method)->orderBy('id')->get();
        $service = app(ManualEvaluation::class);
        $grades = [$this->grade($service->identifier($pair[0]), 0, [false, false]), $this->grade($service->identifier($pair[1]), 1, [true, false])];
        $service->import($report['experiment_id'], $this->datasetFile($grades));
        $export = app(ExportResults::class)->export($report['experiment_id'], $path);
        $groups = json_decode(file_get_contents($export['files']['summary.json']), true)['groups'];
        $graded = array_values(array_filter($groups, fn ($g) => $g['graded_cases'] > 0))[0];
        $this->assertSame([2, 2, 1, 4], [$graded['expected_cases'], $graded['graded_cases'], $graded['facts_covered'], $graded['facts_total']]);
        $this->assertEquals(0.5, $graded['metrics']['correctness']['mean']);
        $this->assertEquals(0.25, $graded['metrics']['required_fact_coverage']['mean']);
        $this->assertSame(2, $graded['metrics']['correctness']['n']);
        $this->assertSame(1, $graded['metrics']['prompt_eval_count']['n']);
        $this->assertEquals(100, $graded['metrics']['prompt_eval_count']['mean']);
        $this->assertEquals(12, $graded['metrics']['eval_count']['mean']);
        $expectedWall = ($pair[0]->details()['inference']['timings_ms']['total_wall'] + $pair[1]->details()['inference']['timings_ms']['total_wall']) / 2;
        $this->assertEqualsWithDelta($expectedWall, $graded['metrics']['total_wall']['mean'], 0.0001);
        $this->assertSame('milliseconds', $graded['metrics']['total_wall']['unit']);
        $this->assertSame('nanoseconds', $graded['metrics']['eval_duration']['unit']);
        $ungraded = array_values(array_filter($groups, fn ($g) => $g['graded_cases'] === 0));
        $this->assertCount(8, $ungraded);
        $this->assertNull($ungraded[0]['metrics']['correctness']['mean']);
    }

    public function test_successful_retries_do_not_change_the_canonical_observation_or_grade_denominator(): void
    {
        $fixture = $this->researchFixture();
        $this->fakeOllama();
        $report = app(RunExperiments::class)->run($fixture['path'], 1, limit: 1);
        app(RunExperiments::class)->run($fixture['path'], 1, limit: 1);
        $attempts = ThesisExperimentAttempt::query()->orderBy('id')->get();
        $service = app(ManualEvaluation::class);
        $service->import($report['experiment_id'], $this->datasetFile([$this->grade($service->identifier($attempts[1]))]));
        $export = app(ExportResults::class)->export($report['experiment_id'], $fixture['path']);
        $groups = json_decode(file_get_contents($export['files']['summary.json']), true)['groups'];
        $observed = array_values(array_filter($groups, fn ($g) => $g['successful_cases'] > 0))[0];
        $this->assertSame([1, 2, 1, 0], [$observed['successful_cases'], $observed['successful_attempts'], $observed['graded_attempts'], $observed['graded_cases']]);
        $this->assertNull($observed['metrics']['correctness']['mean']);
        $this->assertSame(1, $observed['metrics']['prompt_eval_count']['n']);
        $this->assertSame(8, array_sum(array_column($groups, 'missing_cases')));
    }

    public function test_all_nine_missing_combinations_and_category_summaries_are_explicit(): void
    {
        $fixture = $this->researchFixture();
        $other = [...$fixture['row'], 'question_id' => 'synthetic.answer.002', 'category' => 'return_type'];
        $path = $this->datasetFile([$fixture['row'], $other]);
        $preview = app(RunExperiments::class)->run($path, 1, dryRun: true);
        $empty = app(ExportResults::class)->export($preview['experiment_id'], $path, 1);
        $summary = json_decode(file_get_contents($empty['files']['summary.json']), true);
        $this->assertCount(9, $summary['groups']);
        foreach ($summary['groups'] as $group) {
            $this->assertSame([2, 2, 0], [$group['expected_cases'], $group['missing_cases'], $group['successful_cases']]);
            foreach ($group['metrics'] as $metric) {
                $this->assertNull($metric['mean']);
                $this->assertSame(0, $metric['n']);
            }
        }
        $categories = app(ExportResults::class)->export($preview['experiment_id'], $path, 1, byCategory: true);
        $this->assertSame(18, $categories['summary_groups']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('thesis_experiment_attempts', 0);
    }

    public function test_historical_configuration_is_reused_and_mixed_model_versions_are_not_averaged(): void
    {
        $fixture = $this->researchFixture();
        $this->fakeOllama();
        $report = app(RunExperiments::class)->run($fixture['path'], 1, limit: 1);
        config(['thesis.protocol_version' => 'changed', 'thesis.generation.seed' => 999]);
        $export = app(ExportResults::class)->export($report['experiment_id'], $fixture['path']);
        $this->assertSame('1.1.0', json_decode(file_get_contents($export['files']['summary.json']), true)['protocol_version']);
        $changed = $this->datasetFile([[...$fixture['row'], 'dataset_version' => '2.0.0']]);
        $this->assertSame(1, Artisan::call('thesis:results:export', ['experimentId' => $report['experiment_id'], 'datasetPath' => $changed]));
        config(['thesis' => require base_path('config/thesis.php')]);
        $this->fakeOllama(digest: str_repeat('b', 64));
        app(RunExperiments::class)->run($fixture['path'], 1, limit: 1);
        $this->assertSame(1, Artisan::call('thesis:results:export', ['experimentId' => $report['experiment_id'], 'datasetPath' => $fixture['path']]));
        $this->assertSame(0, Artisan::call('thesis:results:export', ['experimentId' => $report['experiment_id'], '--raw-only' => true]));
    }

    public function test_csv_escaping_unicode_multiline_and_formula_injection_protection(): void
    {
        $texts = ["Відповідь, \"так\"\nДругий рядок", '=1+1', ' +SUM(1,2)', '-1+2', '@SUM(A1)', "\t=1", "\r=1", "\u{FEFF}=1", "\u{00A0}=1", "\u{200B}=1"];
        $rows = array_map(fn (string $text): array => ['answer' => $text, 'missing' => null], $texts);
        $paths = app(ResearchFiles::class)->write(str_repeat('a', 64), 'results', ['attempts.csv' => app(ResearchFiles::class)->csv($rows)]);
        $decoded = $this->csv($paths['attempts.csv']);
        $this->assertSame($texts[0], $decoded[0]['answer']);
        foreach (array_slice($texts, 1) as $i => $text) {
            $this->assertSame("'".$text, $decoded[$i + 1]['answer']);
        }
        $this->assertSame('', $decoded[0]['missing']);
    }

    public function test_public_served_and_symlinked_output_paths_are_rejected(): void
    {
        $files = app(ResearchFiles::class);
        foreach ([['root' => storage_path('app/public')], ['serve' => true], ['visibility' => 'public'],
            ['root' => $this->exportRoot.'/../public']] as $settings) {
            $original = config('filesystems.disks.thesis_research');
            config(['filesystems.disks.thesis_research' => [...$original, ...$settings]]);
            try {
                $files->write(str_repeat('a', 64), 'results', ['raw.json' => 'private']);
                $this->fail('Unsafe export destination must be rejected.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            } finally {
                config(['filesystems.disks.thesis_research' => $original]);
            }
        }
        File::makeDirectory($this->exportRoot, 0700, true);
        $link = $this->exportRoot.'/link';
        $this->assertTrue(symlink(storage_path('app/public'), $link));
        config(['filesystems.disks.thesis_research.root' => $link]);
        try {
            $files->write(str_repeat('a', 64), 'results', ['raw.json' => 'private']);
            $this->fail('Symbolic links must be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        } finally {
            unlink($link);
        }
        config(['filesystems.disks.thesis_research.root' => $this->exportRoot]);
        $link = $this->exportRoot.'/results';
        $this->assertTrue(symlink(storage_path('app/public'), $link));
        try {
            $files->write(str_repeat('a', 64), 'results', ['raw.json' => 'private']);
            $this->fail('Nested symbolic links must be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        } finally {
            unlink($link);
        }
        $this->assertSame(1, Artisan::call('thesis:grade:export', ['experimentId' => 'invalid']));
        $this->assertSame(1, Artisan::call('thesis:grade:export', ['experimentId' => str_repeat('a', 64), '--seed' => -1]));
        $this->assertSame(1, Artisan::call('thesis:results:export', ['experimentId' => str_repeat('a', 64), '--repetitions' => 0]));
    }

    /** @return array<string, mixed> */
    private function grade(string $id, int $score = 2, array $coverage = [true]): array
    {
        return ['evaluation_id' => $id, 'rubric_version' => '1.0.0', 'correctness' => $score, 'fact_coverage' => $coverage,
            'reviewer' => 'синтетичний-рецензент', 'notes' => 'Synthetic test judgments only.'];
    }

    /** @return list<array<string, mixed>> */
    private function jsonl(string $path): array
    {
        return array_map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($path, FILE_IGNORE_NEW_LINES));
    }

    /** @return list<array<string, string>> */
    private function csv(string $path): array
    {
        $stream = fopen($path, 'r');
        $header = fgetcsv($stream, escape: '');
        $rows = [];
        while (($cells = fgetcsv($stream, escape: '')) !== false) {
            $rows[] = array_combine($header, $cells);
        }
        fclose($stream);

        return $rows;
    }

    /** @param list<string> $statuses */
    private function fakeOllama(array $statuses = [], string $digest = ''): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $digest = $digest === '' ? str_repeat('a', 64) : $digest;
        $index = 0;
        Http::fake(function (Request $request) use (&$index, $statuses, $digest) {
            if (str_ends_with($request->url(), '/api/version')) {
                return Http::response(['version' => 'synthetic-test-runtime']);
            }
            if (str_ends_with($request->url(), '/api/tags')) {
                return Http::response(['models' => array_map(fn ($model) => ['name' => $model, 'digest' => $digest], config('thesis.models'))]);
            }
            if (str_ends_with($request->url(), '/api/show')) {
                return Http::response(['model_info' => ['qwen2.context_length' => 32768], 'capabilities' => ['completion'], 'template' => '{{ .Prompt }}']);
            }
            $status = $statuses[$index++] ?? 'successful';
            if ($status === 'failed') {
                throw new ConnectionException('synthetic timeout');
            }
            $response = ['model' => $request['model'], 'response' => "Відповідь: \"42\".\nЦіле число.", 'done' => true,
                'done_reason' => $status === 'incomplete' ? 'length' : 'stop', 'prompt_eval_count' => 100, 'eval_count' => 12,
                'total_duration' => 1_000_000_000, 'load_duration' => 100_000_000, 'prompt_eval_duration' => 200_000_000, 'eval_duration' => 700_000_000];
            if ($status === 'missing_metrics') {
                unset($response['prompt_eval_count']);
            }

            return Http::response($response);
        });
    }
}
