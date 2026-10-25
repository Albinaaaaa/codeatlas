<?php

namespace Tests\Feature\Research;

use App\Models\CodeChunk;
use App\Models\ThesisExperimentAttempt;
use App\Research\RunExperiments;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\Concerns\ResearchFixture;
use Tests\RefreshDatabase;
use Tests\TestCase;

class ExperimentRunnerTest extends TestCase
{
    use RefreshDatabase, ResearchFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeResearchStorage();
        config(['thesis' => require base_path('config/thesis.php'), 'services.ollama.url' => 'http://ollama.test:11434']);
    }

    public function test_one_question_runs_all_nine_combinations_and_preserves_private_results(): void
    {
        $fixture = $this->researchFixture();
        $row = $fixture['row'];
        $row['reference_answer'] = 'PRIVATE_REFERENCE_SENTINEL';
        $row['required_facts'] = ['PRIVATE_FACT_SENTINEL'];
        $path = $this->datasetFile([$row]);
        $transactionDepth = DB::transactionLevel();
        $this->fakeOllama(transactionDepth: $transactionDepth);
        $report = app(RunExperiments::class)->run($path, 1);
        $this->assertSame([9, 9, 9, 0, 0, 0], [$report['total'], $report['attempted'], $report['completed'], $report['failed'], $report['incomplete'], $report['remaining']]);
        $combinations = [];
        foreach (ThesisExperimentAttempt::query()->get() as $record) {
            $details = $record->details()['inference'];
            $combinations[] = $record->requested_model.'|'.$record->method;
            $this->assertSame($report['experiment_id'], $record->experiment_key);
            $this->assertSame(1, $record->details()['execution']['repetitions']);
            $this->assertSame(9, $record->details()['execution']['total_cases']);
            $this->assertSame(hash('sha256', $details['prompt']), $record->prompt_sha256);
            $this->assertNotEmpty($details['selected_context']);
            $this->assertSame(100, $details['actual_metrics']['prompt_eval_count']);
            $this->assertSame(12, $details['actual_metrics']['eval_count']);
            $this->assertSame(700000000, $details['actual_metrics']['eval_duration']);
            $this->assertSame('nanoseconds', $details['duration_unit']);
            $this->assertSame('test-runtime', $record->details()['model_provenance']['ollama_version']);
        }
        $this->assertCount(9, array_unique($combinations));
        $this->assertCount(9, array_unique(array_column($report['cases'], 'case_key')));
        Http::assertSent(function (Request $request): bool {
            if (! str_ends_with($request->url(), '/api/generate')) {
                return false;
            }
            $body = json_encode($request->data(), JSON_THROW_ON_ERROR);
            foreach (['PRIVATE_REFERENCE_SENTINEL', 'PRIVATE_FACT_SENTINEL', 'gold-location-only.txt', 'reference_answer', 'required_facts', 'verification'] as $private) {
                $this->assertStringNotContainsString($private, $body);
            }

            return true;
        });
    }

    public function test_two_questions_two_repetitions_have_36_deterministic_cases_and_dry_run_writes_nothing(): void
    {
        $fixture = $this->researchFixture();
        $other = [...$fixture['row'], 'question_id' => 'synthetic.answer.002', 'question' => 'What type does answer return?'];
        $path = $this->datasetFile([$fixture['row'], $other]);
        $a = app(RunExperiments::class)->run($path, 2, dryRun: true);
        $b = app(RunExperiments::class)->run($path, 2, dryRun: true, limit: 2, resume: true);
        $this->assertSame(36, $a['total']);
        $this->assertSame(36, $a['remaining']);
        $this->assertSame($a['experiment_id'], $b['experiment_id']);
        $this->assertSame($a['cases'], $b['cases']);
        $this->assertFalse($a['case_identity_final']);
        $this->assertSame(0, Artisan::call('thesis:run', ['datasetPath' => $path, '--repetitions' => 2, '--dry-run' => true]));
        $this->assertSame(36, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['total']);
        $this->assertDatabaseCount('thesis_experiment_attempts', 0);
        Http::assertNothingSent();
        $this->fakeOllama();
        $executed = app(RunExperiments::class)->run($path, 2);
        $this->assertSame([36, 36, 0], [$executed['total'], $executed['completed'], $executed['remaining']]);
        $this->assertDatabaseCount('thesis_experiment_attempts', 36);
    }

    public function test_resume_skips_successes_and_retries_failed_and_incomplete_attempts_separately(): void
    {
        $fixture = $this->researchFixture();
        $this->fakeOllama(['failed', 'incomplete']);
        $runner = app(RunExperiments::class);
        $first = $runner->run($fixture['path'], 1);
        $this->assertSame([7, 1, 1, 2], [$first['completed'], $first['failed'], $first['incomplete'], $first['remaining']]);
        $old = ThesisExperimentAttempt::query()->pluck('attempt_id')->all();
        $this->fakeOllama();
        $resumed = $runner->run($fixture['path'], 1, resume: true);
        $this->assertSame([9, 2, 7, 0], [$resumed['completed'], $resumed['attempted'], $resumed['skipped'], $resumed['remaining']]);
        $this->assertSame(array_column($first['cases'], 'case_key'), array_column($resumed['cases'], 'case_key'));
        $this->assertDatabaseCount('thesis_experiment_attempts', 11);
        $this->assertSame(9, ThesisExperimentAttempt::query()->whereIn('attempt_id', $old)->count());
        $again = $runner->run($fixture['path'], 1, resume: true);
        $this->assertSame([0, 9, 0], [$again['attempted'], $again['skipped'], $again['remaining']]);
        $this->assertDatabaseCount('thesis_experiment_attempts', 11);
    }

    public function test_limit_and_cooperative_interruption_leave_missing_cases_resumable(): void
    {
        $fixture = $this->researchFixture();
        $this->fakeOllama();
        $runner = app(RunExperiments::class);
        $stopped = false;
        $first = $runner->run($fixture['path'], 1, progress: function () use (&$stopped): void {
            $stopped = true;
        }, stop: function () use (&$stopped): bool {
            return $stopped;
        });
        $this->assertTrue($first['interrupted']);
        $this->assertSame([1, 8], [$first['completed'], $first['pending']]);
        $pilot = $runner->run($fixture['path'], 1, limit: 2, resume: true);
        $this->assertSame([3, 2, 1, 6], [$pilot['completed'], $pilot['attempted'], $pilot['skipped'], $pilot['remaining']]);
        $final = $runner->run($fixture['path'], 1, resume: true);
        $this->assertSame([9, 6, 3, 0], [$final['completed'], $final['attempted'], $final['skipped'], $final['remaining']]);
        $this->assertDatabaseCount('thesis_experiment_attempts', 9);
    }

    public function test_configuration_dataset_context_and_model_changes_do_not_reuse_successes(): void
    {
        $fixture = $this->researchFixture();
        $this->fakeOllama();
        $runner = app(RunExperiments::class);
        $first = $runner->run($fixture['path'], 1, limit: 1);
        config(['thesis.protocol_version' => '1.1.1']);
        $changed = $runner->run($fixture['path'], 1, limit: 1, resume: true);
        $this->assertNotSame($first['experiment_id'], $changed['experiment_id']);
        $this->assertSame(0, $changed['skipped']);
        config(['thesis.protocol_version' => '1.1.0', 'thesis.generation.seed' => 43]);
        $this->assertNotSame($first['experiment_id'], $runner->run($fixture['path'], 1, dryRun: true)['experiment_id']);
        config(['thesis.generation.seed' => 42]);
        $row = [...$fixture['row'], 'dataset_version' => '1.0.1'];
        $this->assertNotSame($first['experiment_id'], $runner->run($this->datasetFile([$row]), 1, dryRun: true)['experiment_id']);
        $this->fakeOllama(digest: str_repeat('b', 64));
        $modelsChanged = $runner->run($fixture['path'], 1, limit: 1, resume: true);
        $this->assertSame($first['experiment_id'], $modelsChanged['experiment_id']);
        $this->assertNotSame(array_column($first['cases'], 'case_key'), array_column($modelsChanged['cases'], 'case_key'));
        $this->assertSame(0, $modelsChanged['skipped']);
        $this->fakeOllama(availableMetadata: false);
        $unknown = $runner->run($fixture['path'], 1, limit: 1, resume: true);
        $unknownAgain = $runner->run($fixture['path'], 1, limit: 1, resume: true);
        $this->assertSame(0, $unknownAgain['skipped']);
        $this->assertFalse($unknown['cases'][0]['provenance_verified']);
        $chunk = CodeChunk::query()->where('project_revision_id', $fixture['revision']->id)->firstOrFail();
        $chunk->update(['content' => $chunk->content."\n// changed indexed context"]);
        $this->assertNotSame($first['experiment_id'], $runner->run($fixture['path'], 1, dryRun: true)['experiment_id']);
    }

    public function test_abrupt_interruption_preserves_saved_attempts_and_resume_completes_the_matrix(): void
    {
        $fixture = $this->researchFixture();
        $this->fakeOllama(['successful', 'successful', 'interrupt']);
        try {
            app(RunExperiments::class)->run($fixture['path'], 1);
            $this->fail('Expected simulated process interruption.');
        } catch (RuntimeException $error) {
            $this->assertSame('simulated interruption', $error->getMessage());
        }
        $this->assertDatabaseCount('thesis_experiment_attempts', 2);
        $this->fakeOllama();
        $report = app(RunExperiments::class)->run($fixture['path'], 1, resume: true);
        $this->assertSame([9, 7, 2, 0], [$report['completed'], $report['attempted'], $report['skipped'], $report['remaining']]);
        $this->assertDatabaseCount('thesis_experiment_attempts', 9);
    }

    public function test_invalid_datasets_missing_revisions_chunks_and_options_fail_before_http(): void
    {
        $fixture = $this->researchFixture();
        foreach ([['revision_id' => 2147483647], ['revision_fingerprint' => str_repeat('0', 64)], ['question' => '']] as $change) {
            try {
                app(RunExperiments::class)->run($this->datasetFile([[...$fixture['row'], ...$change]]), 1);
                $this->fail('Invalid dataset should stop before inference.');
            } catch (InvalidArgumentException) {
                $this->assertDatabaseCount('thesis_experiment_attempts', 0);
            }
        }
        foreach ([['--repetitions' => 0], ['--repetitions' => 'x'], ['--limit' => -1]] as $options) {
            $this->assertSame(1, Artisan::call('thesis:run', ['datasetPath' => $fixture['path'], ...$options]));
        }
        CodeChunk::query()->where('project_revision_id', $fixture['revision']->id)->delete();
        $this->assertSame(1, Artisan::call('thesis:run', ['datasetPath' => $fixture['path'], '--dry-run' => true]));
        Http::assertNothingSent();
    }

    public function test_cli_limit_reports_incomplete_and_does_not_print_private_prompt(): void
    {
        $fixture = $this->researchFixture();
        $this->fakeOllama();
        $this->assertSame(2, Artisan::call('thesis:run', ['datasetPath' => $fixture['path'], '--repetitions' => 1, '--limit' => 2]));
        $output = Artisan::output();
        $this->assertStringContainsString('"remaining": 7', $output);
        $this->assertStringNotContainsString('return 42', $output);
        $this->assertStringNotContainsString($fixture['row']['reference_answer'], $output);
        $this->assertDatabaseCount('thesis_experiment_attempts', 2);
    }

    /** @param list<string> $statuses */
    private function fakeOllama(array $statuses = [], string $digest = '', bool $availableMetadata = true, ?int $transactionDepth = null): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $digest = $digest === '' ? str_repeat('a', 64) : $digest;
        $index = 0;
        Http::fake(function (Request $request) use (&$index, $statuses, $digest, $availableMetadata, $transactionDepth) {
            if ($transactionDepth !== null) {
                $this->assertSame($transactionDepth, DB::transactionLevel(), 'Runner must not open a transaction around HTTP.');
            }
            if (str_ends_with($request->url(), '/api/version')) {
                return Http::response($availableMetadata ? ['version' => 'test-runtime'] : [], $availableMetadata ? 200 : 503);
            }
            if (str_ends_with($request->url(), '/api/tags')) {
                return Http::response(['models' => $availableMetadata ? array_map(fn ($model) => ['name' => $model, 'digest' => $digest], config('thesis.models')) : []]);
            }
            if (str_ends_with($request->url(), '/api/show')) {
                return Http::response(['model_info' => ['qwen2.context_length' => 32768], 'capabilities' => ['completion'], 'template' => '{{ .Prompt }}']);
            }
            $this->assertStringEndsWith('/api/generate', $request->url());
            $status = $statuses[$index++] ?? 'successful';
            if ($status === 'failed') {
                throw new ConnectionException('test timeout');
            }
            if ($status === 'interrupt') {
                throw new RuntimeException('simulated interruption');
            }

            return Http::response(['model' => $request['model'], 'response' => '42', 'done' => true,
                'done_reason' => $status === 'incomplete' ? 'length' : 'stop', 'prompt_eval_count' => 100, 'eval_count' => 12,
                'total_duration' => 1_000_000_000, 'load_duration' => 100_000_000, 'prompt_eval_duration' => 200_000_000, 'eval_duration' => 700_000_000]);
        });
    }
}
