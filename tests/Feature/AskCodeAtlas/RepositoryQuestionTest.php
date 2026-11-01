<?php

namespace Tests\Feature\AskCodeAtlas;

use App\CodeChunking\BuildRevisionChunks;
use App\Inference\OllamaInference;
use App\Jobs\AnswerRepositoryQuestionJob;
use App\Models\CodeChunk;
use App\Models\ProjectRevision;
use App\Models\RepositoryQuestion;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\ResearchFixture;
use Tests\RefreshDatabase;
use Tests\TestCase;

class RepositoryQuestionTest extends TestCase
{
    use RefreshDatabase, ResearchFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeResearchStorage();
        config(['thesis' => require base_path('config/thesis.php'), 'services.ollama.url' => 'http://ollama.test:11434']);
    }

    public function test_authenticated_owner_can_queue_and_reopen_a_private_answer_with_source_metrics(): void
    {
        $fixture = $this->researchFixture();
        $project = $fixture['revision']->project;
        $owner = $project->owner;
        Queue::fake();
        $payload = $this->payload($fixture['revision']->id);

        $response = $this->actingAs($owner)->postJson('/projects/'.$project->id.'/ask', $payload)
            ->assertAccepted()->assertJsonPath('status', 'queued');
        $question = RepositoryQuestion::query()->firstOrFail();
        Queue::assertPushed(AnswerRepositoryQuestionJob::class, fn (AnswerRepositoryQuestionJob $job): bool => $job->questionId === $question->id);
        $this->assertStringNotContainsString($payload['question'], (string) DB::table('repository_questions')->where('id', $question->id)->value('question'));
        CodeChunk::query()->where('project_revision_id', $fixture['revision']->id)->delete();

        Http::fake(fn (Request $request) => str_ends_with($request->url(), '/api/show')
            ? Http::response(['template' => '{{ .Prompt }}', 'system' => '', 'model_info' => ['qwen2.context_length' => 8192], 'capabilities' => ['completion']])
            : Http::response(['model' => 'qwen2.5-coder:7b', 'done' => true, 'done_reason' => 'stop', 'response' => 'A generated answer.',
                'prompt_eval_count' => 123, 'eval_count' => 25, 'total_duration' => 456000000, 'load_duration' => 100,
                'prompt_eval_duration' => 200, 'eval_duration' => 300]));

        (new AnswerRepositoryQuestionJob($question->id))->handle(app(BuildRevisionChunks::class), app(OllamaInference::class));

        $this->actingAs($owner)->getJson('/projects/'.$project->id.'/ask/'.$question->public_id)
            ->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('answer', 'A generated answer.')
            ->assertJsonPath('metrics.actual_input_tokens', 123)->assertJsonPath('metrics.actual_output_tokens', 25)
            ->assertJsonPath('metrics.inference_duration_ns', 456000000)
            ->assertJsonMissingPath('prompt')->assertJsonMissingPath('source_context');
        $stored = $question->fresh();
        $this->assertSame('completed', $stored->status);
        $this->assertNotEmpty($stored->source_context);
        $this->assertNotEmpty($stored->source_chunks);
        $this->assertStringNotContainsString(Storage::disk('repository_snapshots')->path(''), json_encode($stored->source_context));
        $this->assertStringNotContainsString('A generated answer.', (string) DB::table('repository_questions')->where('id', $stored->id)->value('answer'));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/generate')
            && $request->data()['model'] === 'qwen2.5-coder:7b'
            && str_contains($request->data()['prompt'], $payload['question']));
    }

    public function test_project_revision_validation_and_ownership_prevent_foreign_access(): void
    {
        $fixture = $this->researchFixture();
        $project = $fixture['revision']->project;
        $other = User::factory()->create();

        $this->postJson('/projects/'.$project->id.'/ask', $this->payload($fixture['revision']->id))->assertUnauthorized();
        $this->actingAs($other)->postJson('/projects/'.$project->id.'/ask', $this->payload($fixture['revision']->id))->assertForbidden();
        $foreign = $this->researchFixture($other->projects()->create(['name' => 'Other project', 'slug' => 'other-project']));
        $this->actingAs($project->owner)->postJson('/projects/'.$project->id.'/ask', $this->payload($foreign['revision']->id))->assertUnprocessable();
        $this->actingAs($project->owner)->getJson('/projects/'.$project->id.'/ask/'.$this->foreignQuestion($other, $foreign['revision'])->public_id)->assertNotFound();
    }

    public function test_repeated_idempotency_key_is_reused_but_cannot_change_selected_configuration(): void
    {
        $fixture = $this->researchFixture();
        $project = $fixture['revision']->project;
        $owner = $project->owner;
        Queue::fake();
        $payload = $this->payload($fixture['revision']->id);
        $this->actingAs($owner)->postJson('/projects/'.$project->id.'/ask', $payload)->assertAccepted();
        $this->actingAs($owner)->postJson('/projects/'.$project->id.'/ask', $payload)->assertAccepted();
        $this->assertSame(1, RepositoryQuestion::query()->count());
        Queue::assertPushed(AnswerRepositoryQuestionJob::class, 1);

        $payload['model'] = 'qwen2.5-coder:14b';
        $this->actingAs($owner)->postJson('/projects/'.$project->id.'/ask', $payload)->assertStatus(409);
        $this->actingAs($owner)->postJson('/projects/'.$project->id.'/ask', [...$payload, 'idempotency_key' => (string) Str::uuid(), 'retrieval_method' => 'vector'])
            ->assertUnprocessable();
    }

    public function test_model_question_length_and_retrieval_method_are_validated_server_side(): void
    {
        $fixture = $this->researchFixture();
        $project = $fixture['revision']->project;
        $payload = $this->payload($fixture['revision']->id);
        $this->actingAs($project->owner)->postJson('/projects/'.$project->id.'/ask', [...$payload, 'model' => 'qwen2.5-coder:32b'])
            ->assertUnprocessable()->assertJsonValidationErrors('model');
        $this->actingAs($project->owner)->postJson('/projects/'.$project->id.'/ask', [...$payload, 'question' => str_repeat('x', 4001)])
            ->assertUnprocessable()->assertJsonValidationErrors('question');
    }

    public function test_truncated_model_response_is_saved_as_incomplete_not_successful(): void
    {
        $fixture = $this->researchFixture();
        $project = $fixture['revision']->project;
        Queue::fake();
        $payload = $this->payload($fixture['revision']->id);
        $this->actingAs($project->owner)->postJson('/projects/'.$project->id.'/ask', $payload)->assertAccepted();
        $question = RepositoryQuestion::query()->firstOrFail();
        Http::fake(fn (Request $request) => str_ends_with($request->url(), '/api/show')
            ? Http::response(['template' => '{{ .Prompt }}', 'model_info' => ['qwen2.context_length' => 8192], 'capabilities' => ['completion']])
            : Http::response(['model' => $payload['model'], 'done' => true, 'done_reason' => 'length', 'response' => 'Partial answer.']));

        (new AnswerRepositoryQuestionJob($question->id))->handle(app(BuildRevisionChunks::class), app(OllamaInference::class));

        $this->actingAs($project->owner)->getJson('/projects/'.$project->id.'/ask/'.$question->public_id)
            ->assertOk()->assertJsonPath('status', 'incomplete')->assertJsonPath('answer', 'Partial answer.')
            ->assertJsonPath('completion.stop_reason', 'length');
    }

    public function test_history_lists_only_indexed_owned_revisions_and_question_metadata(): void
    {
        $fixture = $this->researchFixture();
        $project = $fixture['revision']->project;

        $this->actingAs($project->owner)->getJson('/projects/'.$project->id.'/ask/history')
            ->assertOk()->assertJsonPath('revisions.0.id', $fixture['revision']->id)->assertJsonPath('questions', []);
    }

    public function test_ask_page_is_project_authorized_and_receives_backend_configuration(): void
    {
        config(['inertia.ssr.enabled' => false]);
        $fixture = $this->researchFixture();
        $project = $fixture['revision']->project;

        $this->actingAs($project->owner)->get('/projects/'.$project->id.'/ask')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/ask/index')
                ->where('project.id', $project->id)
                ->where('configuration.models', config('thesis.models'))
                ->where('configuration.methods', config('thesis.methods'))
                ->has('endpoints.history')
                ->has('endpoints.store')
                ->has('endpoints.detail'));

        $this->actingAs(User::factory()->create())->get('/projects/'.$project->id.'/ask')->assertForbidden();
    }

    public function test_ollama_unavailable_is_saved_as_a_safe_failure(): void
    {
        $fixture = $this->researchFixture();
        $project = $fixture['revision']->project;
        $owner = $project->owner;
        Queue::fake();
        $payload = $this->payload($fixture['revision']->id);
        $this->actingAs($owner)->postJson('/projects/'.$project->id.'/ask', $payload)->assertAccepted();
        $question = RepositoryQuestion::query()->firstOrFail();
        Http::fake(['*/api/show' => fn () => throw new ConnectionException('private endpoint secret')]);

        (new AnswerRepositoryQuestionJob($question->id))->handle(app(BuildRevisionChunks::class), app(OllamaInference::class));

        $this->assertSame('failed', $question->fresh()->status);
        $this->assertStringNotContainsString('secret', $question->fresh()->safe_error);
        $this->assertSame('Ollama is unavailable or the inference request timed out.', $question->fresh()->safe_error);
    }

    public function test_ask_and_research_share_one_expiring_ollama_lock(): void
    {
        $fixture = $this->researchFixture();
        config(['services.ollama.lock_store' => 'array', 'services.ollama.lock_wait_seconds' => 1,
            'thesis.inference.timeout_seconds' => 1]);
        $lock = Cache::store('array')->lock('codeatlas:ollama-inference', 61);
        $this->assertTrue($lock->get());
        $this->expectException(LockTimeoutException::class);

        app(OllamaInference::class)->ask($fixture['revision']->id, 'What does this repository do?', 'baseline', 'qwen2.5-coder:7b');
    }

    public function test_stale_background_execution_is_recovered_without_replaying_inference(): void
    {
        $fixture = $this->researchFixture();
        $project = $fixture['revision']->project;
        $question = RepositoryQuestion::query()->create([
            'public_id' => (string) Str::uuid(), 'user_id' => $project->user_id, 'project_id' => $project->id,
            'project_revision_id' => $fixture['revision']->id, 'revision_fingerprint' => $fixture['row']['revision_fingerprint'],
            'question' => 'This worker stopped.', 'requested_model' => 'qwen2.5-coder:7b', 'retrieval_method' => 'baseline',
            'status' => 'running', 'idempotency_key' => (string) Str::uuid(), 'request_sha256' => str_repeat('c', 64),
            'heartbeat_at' => now()->subHours(2), 'started_at' => now()->subHours(2),
        ]);

        $this->actingAs($project->owner)->getJson('/projects/'.$project->id.'/ask/'.$question->public_id)
            ->assertOk()->assertJsonPath('status', 'incomplete')->assertJsonPath('safe_error',
                'Processing stopped before a result was saved. Submit the question again to retry.');
        $this->assertSame('incomplete', $question->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_stale_queued_question_reports_a_missing_worker_without_duplicate_dispatch(): void
    {
        $fixture = $this->researchFixture();
        $project = $fixture['revision']->project;
        $question = RepositoryQuestion::query()->create([
            'public_id' => (string) Str::uuid(), 'user_id' => $project->user_id, 'project_id' => $project->id,
            'project_revision_id' => $fixture['revision']->id, 'revision_fingerprint' => $fixture['row']['revision_fingerprint'],
            'question' => 'This request was never claimed.', 'requested_model' => 'qwen2.5-coder:7b', 'retrieval_method' => 'baseline',
            'status' => 'queued', 'idempotency_key' => (string) Str::uuid(), 'request_sha256' => str_repeat('d', 64),
            'queued_at' => now()->subHours(2), 'heartbeat_at' => now()->subHours(2),
        ]);

        $this->actingAs($project->owner)->getJson('/projects/'.$project->id.'/ask/'.$question->public_id)
            ->assertOk()->assertJsonPath('status', 'incomplete')->assertJsonPath('safe_error',
                'No worker claimed this request. Check the ask queue and submit again.');
        $this->assertSame('incomplete', $question->fresh()->status);
        Http::assertNothingSent();
    }

    /** @return array<string, mixed> */
    private function payload(int $revisionId): array
    {
        return ['revision_id' => $revisionId, 'question' => 'What does this repository do?', 'model' => 'qwen2.5-coder:7b',
            'retrieval_method' => 'baseline', 'idempotency_key' => (string) Str::uuid()];
    }

    private function foreignQuestion(User $user, ProjectRevision $revision): RepositoryQuestion
    {
        return RepositoryQuestion::query()->create([
            'public_id' => (string) Str::uuid(), 'user_id' => $user->id, 'project_id' => $revision->project_id,
            'project_revision_id' => $revision->id, 'revision_fingerprint' => str_repeat('a', 64), 'question' => 'foreign',
            'requested_model' => 'qwen2.5-coder:7b', 'retrieval_method' => 'baseline', 'status' => 'queued',
            'idempotency_key' => (string) Str::uuid(), 'request_sha256' => str_repeat('b', 64),
        ]);
    }
}
