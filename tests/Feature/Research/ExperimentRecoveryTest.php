<?php

namespace Tests\Feature\Research;

use App\Jobs\RunResearchExperimentBatchJob;
use App\Models\ResearchDataset;
use App\Models\ResearchExperiment;
use App\Models\ThesisExperimentAttempt;
use App\Research\CreateResearchExperiment;
use App\Research\DatasetValidator;
use App\Research\PrepareResearchExperiment;
use App\Research\RecordExperiment;
use App\Research\RunExperiments;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use RuntimeException;
use Tests\Concerns\ResearchFixture;
use Tests\RefreshDatabase;
use Tests\TestCase;

class ExperimentRecoveryTest extends TestCase
{
    use RefreshDatabase, ResearchFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeResearchStorage();
        Storage::fake('thesis_research');
        Queue::fake();
        config(['thesis' => require base_path('config/thesis.php'), 'services.ollama.url' => 'http://ollama.test:11434',
            'inertia.ssr.enabled' => false]);
    }

    public function test_each_batch_saves_one_case_and_checkpoints_provenance_before_inference(): void
    {
        [$experiment] = $this->queuedExperiment();
        $this->fakeOllama(beforeGenerate: function () use ($experiment): void {
            $this->assertCount(3, $experiment->fresh()->model_provenance);
            $this->assertNull($experiment->fresh()->safe_error);
        });
        for ($number = 1; $number <= 9; $number++) {
            $job = new RunResearchExperimentBatchJob($experiment->id, $experiment->execution_identity, $number);
            $job->handle(app(RunExperiments::class));
            $experiment->refresh();
            $this->assertSame($number, $experiment->completed_cases);
            $this->assertSame(9 - $number, $experiment->pending_cases);
            $this->assertDatabaseCount('thesis_experiment_attempts', $number);
            $this->assertSame($number === 9 ? 'completed' : 'queued', $experiment->status);
            if ($number < 9) {
                Queue::assertPushed(RunResearchExperimentBatchJob::class,
                    fn (RunResearchExperimentBatchJob $queued): bool => $queued->batchNumber === $number + 1);
            }
            $state = $experiment->getAttributes();
            $job->handle(app(RunExperiments::class));
            $job->failed(new RuntimeException('late failure from an old batch'));
            $this->assertSame($state, $experiment->fresh()->getAttributes());
        }
        $this->assertSame(9, ThesisExperimentAttempt::query()->distinct()->count('case_key'));
        $this->assertCount(9, Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/api/generate')));
    }

    public function test_resume_recovers_a_saved_success_without_checkpoint_and_never_overwrites_it(): void
    {
        [$experiment, $path] = $this->queuedExperiment();
        $this->fakeOllama();
        app(RunExperiments::class)->run($path, 1, limit: 1);
        $saved = ThesisExperimentAttempt::query()->sole();
        $attributes = $saved->getAttributes();
        $details = $saved->details();
        $experiment->forceFill(['status' => 'running', 'safe_error' => 'old interruption warning'])->save();
        $oldJob = new RunResearchExperimentBatchJob($experiment->id, $experiment->execution_identity, 1);
        $oldJob->failed(new RuntimeException('worker killed after saving the response'));
        $this->assertSame(1, $experiment->fresh()->completed_cases);
        $this->assertSame(8, $experiment->fresh()->pending_cases);

        $url = '/projects/'.$experiment->project_id.'/research/experiments/'.$experiment->id.'/resume';
        $this->actingAs($experiment->owner)->post($url)->assertSessionHasNoErrors();
        $experiment->refresh();
        $this->assertNull($experiment->safe_error);
        $this->post($url)->assertSessionHasErrors('experiment');
        Queue::assertPushed(RunResearchExperimentBatchJob::class, 1);
        $current = $experiment->getAttributes();
        $oldJob->handle(app(RunExperiments::class));
        $oldJob->failed(new RuntimeException('late failure from a previous execution'));
        $this->assertSame($current, $experiment->fresh()->getAttributes());

        (new RunResearchExperimentBatchJob($experiment->id, $experiment->execution_identity, 1, retryFailed: true))
            ->handle(app(RunExperiments::class));
        $this->assertSame(2, $experiment->fresh()->completed_cases);
        $this->assertSame(7, $experiment->fresh()->pending_cases);
        $this->assertSame(1, $experiment->fresh()->skipped_cases);
        $this->assertSame($attributes, $saved->fresh()->getAttributes());
        $this->assertSame($details, $saved->fresh()->details());
        $this->assertDatabaseCount('thesis_experiment_attempts', 2);
        $this->assertCount(2, Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/api/generate')));
    }

    public function test_resume_rejects_changed_or_unavailable_provenance_before_generating_a_duplicate(): void
    {
        [$experiment, $path] = $this->queuedExperiment();
        $this->fakeOllama();
        app(RunExperiments::class)->run($path, 1, limit: 1);
        $saved = ThesisExperimentAttempt::query()->sole()->getAttributes();
        foreach ([str_repeat('b', 64), ''] as $digest) {
            $this->fakeOllama(digest: $digest);
            $experiment->refresh()->forceFill(['status' => 'queued', 'model_provenance' => null])->save();
            try {
                (new RunResearchExperimentBatchJob($experiment->id, $experiment->execution_identity, 1))
                    ->handle(app(RunExperiments::class));
                $this->fail('Changed or unavailable model provenance must stop recovery.');
            } catch (InvalidArgumentException) {
                $this->assertSame('partially_completed', $experiment->fresh()->status);
            }
            $this->assertSame($saved, ThesisExperimentAttempt::query()->sole()->getAttributes());
            Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/generate'));
        }
    }

    public function test_a_success_finishing_after_execution_changes_is_saved_without_clobbering_the_new_execution(): void
    {
        [$experiment] = $this->queuedExperiment();
        $oldIdentity = $experiment->execution_identity;
        $newIdentity = (string) Str::uuid();
        $this->fakeOllama(beforeGenerate: function () use ($experiment, $newIdentity): void {
            $experiment->refresh()->forceFill(['execution_identity' => $newIdentity, 'status' => 'queued', 'batch_number' => 1])->save();
        });
        $job = new RunResearchExperimentBatchJob($experiment->id, $oldIdentity, 1);
        $job->handle(app(RunExperiments::class));
        $job->failed(new RuntimeException('old worker exit'));
        $this->assertSame($newIdentity, $experiment->fresh()->execution_identity);
        $this->assertSame('queued', $experiment->fresh()->status);
        $this->assertSame('successful', ThesisExperimentAttempt::query()->sole()->status);
        Queue::assertNothingPushed();
    }

    public function test_recording_an_already_successful_case_is_idempotent_and_preserves_full_payload(): void
    {
        [, $path] = $this->queuedExperiment();
        $this->fakeOllama();
        app(RunExperiments::class)->run($path, 1, limit: 1);
        $saved = ThesisExperimentAttempt::query()->sole();
        $attributes = $saved->getAttributes();
        $details = $saved->details();
        $question = app(DatasetValidator::class)->validate($path)['questions'][0];
        $record = app(RecordExperiment::class)->record($question, $details['inference'], (string) Str::uuid(), 1,
            $details['model_provenance'], $details['execution']);
        $this->assertSame($saved->id, $record->id);
        $this->assertSame($attributes, $saved->fresh()->getAttributes());
        $this->assertSame($details, $saved->fresh()->details());
        $this->assertDatabaseCount('thesis_experiment_attempts', 1);
        try {
            app(RecordExperiment::class)->record($question, $details['inference'], (string) Str::uuid(), 1,
                [...$details['model_provenance'], 'model_digest' => str_repeat('b', 64)],
                [...$details['execution'], 'case_key' => str_repeat('b', 64)]);
            $this->fail('A different provenance must not duplicate a successful logical case.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('A successful case with different provenance already exists.', $exception->getMessage());
        }
        $this->assertDatabaseCount('thesis_experiment_attempts', 1);
    }

    public function test_stale_running_ui_state_uses_cast_timestamps_and_respects_the_job_time_budget(): void
    {
        [$experiment] = $this->queuedExperiment();
        $this->actingAs($experiment->owner);
        $url = '/projects/'.$experiment->project_id.'/research/experiments/'.$experiment->id;
        $experiment->forceFill(['status' => 'running', 'heartbeat_at' => now()->subMinutes(29)])->save();
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('experiment.status', 'running'));
        $experiment->forceFill(['heartbeat_at' => now()->subSeconds(RunResearchExperimentBatchJob::TIMEOUT_SECONDS + 121)])->save();
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('experiment.status', 'interrupted'));
        $this->assertDatabaseCount('thesis_experiment_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_invalid_operational_timeouts_fail_before_inference_and_do_not_change_frozen_protocol(): void
    {
        [$experiment] = $this->queuedExperiment();
        $identity = $experiment->configuration_identity;
        $this->assertSame(600, config('thesis.inference.timeout_seconds'));
        $this->assertLessThan(config('horizon.defaults.supervisor-research.timeout'), RunResearchExperimentBatchJob::TIMEOUT_SECONDS);
        $this->assertLessThan(config('queue.connections.redis.retry_after'), config('horizon.defaults.supervisor-research.timeout'));
        $this->assertGreaterThan(config('queue.connections.redis.retry_after'), (new RunResearchExperimentBatchJob(1, '', 1))->uniqueFor);
        config(['queue.connections.redis.retry_after' => 1500]);
        try {
            (new RunResearchExperimentBatchJob($experiment->id, $experiment->execution_identity, 1))
                ->handle(app(RunExperiments::class));
            $this->fail('Unsafe queue timeout ordering must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Research requires job timeout < Horizon timeout < Redis retry_after.', $exception->getMessage());
        }
        $this->assertSame($identity, hash('sha256', json_encode(config('thesis'), JSON_THROW_ON_ERROR)));
        Http::assertNothingSent();
        $this->assertDatabaseCount('thesis_experiment_attempts', 0);
    }

    public function test_inference_cannot_start_when_the_lock_wait_exhausts_the_job_budget(): void
    {
        [$experiment] = $this->queuedExperiment();
        $this->fakeOllama();
        config(['services.ollama.lock_wait_seconds' => RunResearchExperimentBatchJob::TIMEOUT_SECONDS]);
        try {
            (new RunResearchExperimentBatchJob($experiment->id, $experiment->execution_identity, 1))
                ->handle(app(RunExperiments::class));
            $this->fail('Inference must not start without enough time to save its response.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Research planning exhausted the safe inference time budget.', $exception->getMessage());
        }
        $this->assertSame('partially_completed', $experiment->fresh()->status);
        $this->assertDatabaseCount('thesis_experiment_attempts', 0);
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/generate'));
    }

    public function test_http_timeouts_preserve_successes_and_each_failed_case_is_retried_once_per_manual_round(): void
    {
        [$experiment] = $this->queuedExperiment();
        $generated = 0;
        $this->fakeOllama(beforeGenerate: function () use (&$generated): void {
            if (in_array(++$generated, [2, 3], true)) {
                throw new ConnectionException('simulated inference timeout');
            }
        });
        $saved = null;
        for ($batch = 1; $batch <= 9; $batch++) {
            (new RunResearchExperimentBatchJob($experiment->id, $experiment->execution_identity, $batch))
                ->handle(app(RunExperiments::class));
            if ($batch === 1) {
                $saved = ThesisExperimentAttempt::query()->sole()->getAttributes();
            }
            $experiment->refresh();
            $this->assertSame($batch, $experiment->attempted_cases);
        }
        $this->assertSame(9, $generated);
        $this->assertSame([7, 2, 0, 'partially_completed'], [$experiment->completed_cases, $experiment->failed_cases,
            $experiment->pending_cases, $experiment->status]);
        $url = '/projects/'.$experiment->project_id.'/research/experiments/'.$experiment->id.'/resume';
        $this->actingAs($experiment->owner);

        for ($round = 1; $round <= 2; $round++) {
            $cutoff = (int) ThesisExperimentAttempt::query()->max('id');
            $this->post($url)->assertSessionHasNoErrors();
            $experiment->refresh();
            $roundRequests = 0;
            $this->fakeOllama(beforeGenerate: function () use (&$roundRequests, $round): void {
                $roundRequests++;
                if ($round === 1) {
                    throw new ConnectionException('simulated repeated inference timeout');
                }
            });
            for ($batch = 1; $batch <= 2; $batch++) {
                (new RunResearchExperimentBatchJob($experiment->id, $experiment->execution_identity, $batch,
                    retryFailed: true, retryBeforeAttemptId: $cutoff))->handle(app(RunExperiments::class));
                $experiment->refresh();
                if ($batch === 1) {
                    $this->assertSame('queued', $experiment->status);
                    Queue::assertPushed(RunResearchExperimentBatchJob::class,
                        fn (RunResearchExperimentBatchJob $job): bool => $job->executionIdentity === $experiment->execution_identity
                            && $job->batchNumber === 2 && $job->retryFailed && $job->retryBeforeAttemptId === $cutoff);
                }
            }
            $this->assertSame(2, $roundRequests);
            $this->assertSame($round, $experiment->manual_retry_rounds);
            $this->assertSame($round === 1 ? 'partially_completed' : 'completed', $experiment->status);
            Queue::assertNotPushed(RunResearchExperimentBatchJob::class,
                fn (RunResearchExperimentBatchJob $job): bool => $job->executionIdentity === $experiment->execution_identity && $job->batchNumber === 3);
        }
        $this->assertSame([9, 0, 0], [$experiment->completed_cases, $experiment->failed_cases, $experiment->pending_cases]);
        $this->assertDatabaseCount('thesis_experiment_attempts', 13);
        $this->assertSame(9, ThesisExperimentAttempt::query()->where('status', 'successful')->count());
        $this->assertSame($saved, ThesisExperimentAttempt::query()->oldest('id')->firstOrFail()->getAttributes());
        foreach (ThesisExperimentAttempt::query()->get() as $attempt) {
            $details = $attempt->details();
            $this->assertSame($experiment->configuration_identity, hash('sha256', json_encode($details['inference']['protocol_configuration'], JSON_THROW_ON_ERROR)));
            $this->assertSame(600, $details['inference']['request']['timeout_seconds']);
        }
    }

    public function test_resuming_pending_cases_does_not_consume_failed_case_retry_rounds(): void
    {
        [$experiment] = $this->queuedExperiment();
        $experiment->forceFill(['status' => 'interrupted', 'manual_retry_rounds' => 2])->save();
        $this->actingAs($experiment->owner)->post('/projects/'.$experiment->project_id.'/research/experiments/'.$experiment->id.'/resume')
            ->assertSessionHasNoErrors();
        $this->assertSame('queued', $experiment->fresh()->status);
        $this->assertSame(2, $experiment->fresh()->manual_retry_rounds);
        Queue::assertPushed(RunResearchExperimentBatchJob::class,
            fn (RunResearchExperimentBatchJob $job): bool => ! $job->retryFailed && $job->retryBeforeAttemptId === null);
        Http::assertNothingSent();
    }

    /** @return array{ResearchExperiment, string} */
    private function queuedExperiment(): array
    {
        $fixture = $this->researchFixture();
        $project = $fixture['revision']->project;
        $raw = (string) file_get_contents($fixture['path']);
        Storage::disk('thesis_research')->put('frozen.jsonl', $raw);
        $dataset = ResearchDataset::forceCreate(['project_id' => $project->id, 'project_revision_id' => $fixture['revision']->id,
            'dataset_id' => $fixture['row']['dataset_id'], 'dataset_version' => $fixture['row']['dataset_version'], 'split' => 'pilot',
            'status' => 'frozen', 'questions' => [$fixture['row']], 'frozen_path' => 'frozen.jsonl', 'frozen_sha256' => hash('sha256', $raw)]);
        $experiment = app(CreateResearchExperiment::class)->handle($project, $dataset, $project->owner, 1);
        app(PrepareResearchExperiment::class)->handle($experiment);
        $experiment->refresh()->forceFill(['status' => 'queued', 'execution_identity' => (string) Str::uuid(),
            'batch_number' => 1, 'safe_error' => 'old interruption warning'])->save();

        return [$experiment, Storage::disk('thesis_research')->path('frozen.jsonl')];
    }

    private function fakeOllama(string $digest = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', ?Closure $beforeGenerate = null): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($digest, $beforeGenerate) {
            if (str_ends_with($request->url(), '/api/version')) {
                return Http::response(['version' => 'test-runtime']);
            }
            if (str_ends_with($request->url(), '/api/tags')) {
                return Http::response(['models' => $digest === '' ? [] : array_map(fn ($model) => ['name' => $model, 'digest' => $digest], config('thesis.models'))]);
            }
            if (str_ends_with($request->url(), '/api/show')) {
                return Http::response(['model_info' => ['qwen2.context_length' => 32768], 'capabilities' => ['completion'], 'template' => '{{ .Prompt }}']);
            }
            $this->assertStringEndsWith('/api/generate', $request->url());
            $beforeGenerate?->__invoke();

            return Http::response(['model' => $request['model'], 'response' => '42', 'done' => true, 'done_reason' => 'stop',
                'prompt_eval_count' => 100, 'eval_count' => 12, 'total_duration' => 1_000_000_000]);
        });
    }
}
