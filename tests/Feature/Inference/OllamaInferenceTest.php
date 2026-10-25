<?php

namespace Tests\Feature\Inference;

use App\CodeChunking\BuildRevisionChunks;
use App\ContextBuilding\BuildContext;
use App\Inference\OllamaInference;
use App\Inference\OllamaInferenceException;
use App\Models\IndexRun;
use App\Models\ProjectRevision;
use App\Models\User;
use App\PhpAnalysis\PhpRevisionAnalyzer;
use App\RepositoryScanning\RepositoryInventoryScanner;
use App\RepositoryScanning\ResolvedRepositorySnapshot;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\RefreshDatabase;
use Tests\TestCase;

class OllamaInferenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('repository_snapshots');
        $this->resetHttp();
        config(['thesis' => require base_path('config/thesis.php'), 'services.ollama.url' => 'http://ollama.test:11434']);
    }

    #[DataProvider('models')]
    public function test_supported_models_receive_the_exact_context_prompt_and_report_actual_metrics(string $model): void
    {
        $revision = $this->indexed();
        $context = app(BuildContext::class)->build($revision->id, 'What does answer return?', 'keyword', $model);
        $this->fakeSuccess($model);
        $result = app(OllamaInference::class)->ask($revision->id, 'What does answer return?', 'keyword', $model);
        $this->assertSame($context['prompt'], $result['prompt']);
        $this->assertSame($context['prompt_hash'], $result['prompt_hash']);
        $this->assertSame(hash('sha256', $result['prompt']), $result['prompt_hash']);
        $this->assertSame($context['selected_chunks'], $result['selected_chunks']);
        $this->assertSame($context['token_usage'], $result['estimated_token_usage']);
        $this->assertSame($revision->identifier, $result['revision_identifier']);
        $this->assertSame($model, $result['requested_model']);
        $this->assertSame($model, $result['returned_model']);
        $this->assertSame('answer.php returns 42.', $result['answer']);
        $this->assertSame(['status' => 'complete', 'done' => true, 'stop_reason' => 'stop'], $result['completion']);
        $this->assertSame(50, $result['actual_metrics']['prompt_eval_count']);
        $this->assertSame(20, $result['actual_metrics']['eval_count']);
        $this->assertSame(1_000_000_000, $result['actual_metrics']['total_duration']);
        $this->assertSame(200_000_000, $result['actual_metrics']['load_duration']);
        $this->assertSame(300_000_000, $result['actual_metrics']['prompt_eval_duration']);
        $this->assertSame(500_000_000, $result['actual_metrics']['eval_duration']);
        $this->assertSame('nanoseconds', $result['duration_unit']);
        $this->assertSame([], $result['metrics_unavailable']);
        $this->assertSame($context['token_usage']['prompt_tokens'] - 50, $result['token_comparison']['estimated_minus_actual']);
        $this->assertTrue($result['token_comparison']['actual_prompt_plus_output_reserve_fits_num_ctx']);
        foreach ($result['timings_ms'] as $time) {
            $this->assertGreaterThanOrEqual(0, $time);
        }
        Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/api/generate')
            && $r['model'] === $model && $r['prompt'] === $context['prompt'] && $r['stream'] === false && $r['raw'] === false
            && ! isset($r['system']) && ! isset($r['template']) && $r['options']['num_ctx'] === 8192 && $r['options']['num_predict'] === 512);
        Http::assertSentCount(2);
        $this->assertSame(hash('sha256', $this->metadata()['template']), $result['model_metadata']['template_sha256']);
        $this->assertSame(hash('sha256', $this->metadata()['system']), $result['model_metadata']['system_sha256']);
    }

    public static function models(): array
    {
        return [['qwen2.5-coder:1.5b'], ['qwen2.5-coder:7b'], ['qwen2.5-coder:14b']];
    }

    public function test_all_retrieval_methods_use_identical_generation_options_and_configured_timeouts(): void
    {
        $revision = $this->indexed();
        config(['thesis.inference.timeout_seconds' => 600, 'thesis.generation.temperature' => 0.2,
            'thesis.generation.seed' => 123, 'thesis.context_window_tokens' => 4096, 'thesis.max_output_tokens' => 64]);
        $options = ['temperature' => 0.2, 'seed' => 123, 'top_k' => 40, 'top_p' => 0.9, 'repeat_penalty' => 1.1, 'num_ctx' => 4096, 'num_predict' => 64];
        Http::fake(function (Request $request, array $transport) use ($options) {
            $this->assertFalse($transport['allow_redirects']);
            $this->assertSame(3, $transport['connect_timeout']);
            if (str_ends_with($request->url(), '/api/show')) {
                $this->assertSame(10, $transport['timeout']);

                return Http::response($this->metadata());
            }
            $this->assertSame(600, $transport['timeout']);
            $this->assertEquals($options, $request['options']);

            return Http::response(self::reply());
        });
        foreach (['baseline', 'keyword', 'structure_aware'] as $method) {
            $this->assertSame(0, Artisan::call('thesis:ask', ['revisionId' => $revision->id, 'question' => 'answer', '--method' => $method]));
            $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($method, $result['method']);
            $this->assertEquals($options, $result['generation_options']);
            $this->assertSame(hash('sha256', $result['prompt']), $result['prompt_hash']);
        }
        Http::assertSentCount(6);
    }

    public function test_missing_metrics_are_null_and_not_fabricated_zeroes(): void
    {
        $revision = $this->indexed();
        $this->fakeSuccess(body: ['model' => 'qwen2.5-coder:1.5b', 'response' => '42', 'done' => true, 'done_reason' => 'stop']);
        $result = app(OllamaInference::class)->ask($revision->id, 'answer', 'keyword', 'qwen2.5-coder:1.5b');
        $this->assertCount(6, $result['metrics_unavailable']);
        $this->assertSame([null], array_values(array_unique(array_values($result['actual_metrics']))));
        $this->assertNull($result['token_comparison']['actual_prompt_tokens']);
        $this->assertNull($result['token_comparison']['estimated_minus_actual']);
        $this->assertNull($result['token_comparison']['actual_prompt_plus_output_reserve_fits_num_ctx']);
    }

    public function test_truncated_and_unknown_completion_reasons_are_not_successful_complete_answers(): void
    {
        $revision = $this->indexed();
        foreach (['length' => 'truncated', 'unexpected' => 'unknown', '' => 'unknown'] as $reason => $status) {
            $body = [...self::reply(), 'response' => '<question>Partial 42</question>'];
            if ($reason !== '') {
                $body['done_reason'] = $reason;
            } else {
                unset($body['done_reason']);
            }
            $this->fakeSuccess(body: $body);
            $this->assertSame(2, Artisan::call('thesis:ask', ['revisionId' => $revision->id, 'question' => 'answer']));
            $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($status, $result['completion']['status']);
            $this->assertSame($reason === '' ? null : $reason, $result['completion']['stop_reason']);
            $this->assertSame('<question>Partial 42</question>', $result['answer']);
        }
    }

    public function test_actual_tokens_exceeding_the_requested_window_are_flagged(): void
    {
        $revision = $this->indexed();
        $this->fakeSuccess(body: [...self::reply(), 'prompt_eval_count' => 8192]);
        $this->assertSame(2, Artisan::call('thesis:ask', ['revisionId' => $revision->id, 'question' => 'answer']));
        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($result['token_comparison']['actual_prompt_plus_output_reserve_fits_num_ctx']);
    }

    #[DataProvider('badResponses')]
    public function test_malformed_incomplete_or_empty_generation_fails_without_echoing_response_bodies(mixed $body): void
    {
        $revision = $this->indexed();
        $this->fakeSuccess(body: $body);
        $this->assertSame(1, Artisan::call('thesis:ask', ['revisionId' => $revision->id, 'question' => 'answer']));
        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringNotContainsString('PRIVATE_SOURCE', Artisan::output());
    }

    public static function badResponses(): array
    {
        $base = self::reply();

        return [
            'invalid json' => ['PRIVATE_SOURCE not JSON'],
            'list' => [[]],
            'api error' => [['error' => 'PRIVATE_SOURCE']],
            'partial' => [[...$base, 'done' => false, 'response' => 'PRIVATE_SOURCE']],
            'missing done' => [array_diff_key($base, ['done' => true])],
            'missing model' => [array_diff_key($base, ['model' => true])],
            'wrong model' => [[...$base, 'model' => 'qwen2.5-coder:7b']],
            'missing response' => [array_diff_key($base, ['response' => true])],
            'empty response' => [[...$base, 'response' => '   ']],
            'bad reason' => [[...$base, 'done_reason' => []]],
            'bad metric' => [[...$base, 'eval_count' => '20']],
            'negative duration' => [[...$base, 'load_duration' => -1]],
        ];
    }

    public function test_http_errors_and_missing_installed_model_are_explicit_without_downloading(): void
    {
        $revision = $this->indexed();
        foreach (['show' => 404, 'generate' => 503] as $stage => $status) {
            $this->resetHttp();
            Http::fake([
                '*/api/show' => Http::response($stage === 'show' ? ['error' => 'PRIVATE_SOURCE'] : $this->metadata(), $stage === 'show' ? $status : 200),
                '*/api/generate' => Http::response(['error' => 'PRIVATE_SOURCE'], $status),
            ]);
            $this->assertSame(1, Artisan::call('thesis:ask', ['revisionId' => $revision->id, 'question' => 'answer']));
            $this->assertStringContainsString('HTTP '.$status, Artisan::output());
            $this->assertStringNotContainsString('PRIVATE_SOURCE', Artisan::output());
        }
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/pull'));
    }

    public function test_connection_failures_and_timeouts_do_not_retain_sensitive_transport_exceptions(): void
    {
        $revision = $this->indexed();
        foreach (['show', 'generate'] as $stage) {
            $this->resetHttp();
            Http::fake([
                '*/api/show' => $stage === 'show' ? fn () => throw new ConnectionException('PRIVATE_SOURCE connection refused') : Http::response($this->metadata()),
                '*/api/generate' => fn () => throw new ConnectionException('cURL error 28 PRIVATE_SOURCE timeout'),
            ]);
            try {
                app(OllamaInference::class)->ask($revision->id, 'answer', 'keyword', 'qwen2.5-coder:1.5b');
                $this->fail('Expected a connection failure.');
            } catch (OllamaInferenceException $exception) {
                $this->assertNull($exception->getPrevious());
                $this->assertInstanceOf(ShouldntReport::class, $exception);
                $this->assertStringContainsString('connection failure or timeout', $exception->getMessage());
                $this->assertStringNotContainsString('PRIVATE_SOURCE', $exception->getMessage());
            }
            $this->assertSame(1, Artisan::call('thesis:ask', ['revisionId' => $revision->id, 'question' => 'answer']));
            $this->assertStringNotContainsString('PRIVATE_SOURCE', Artisan::output());
        }
    }

    public function test_model_limits_and_completion_capabilities_are_checked_before_generation(): void
    {
        $revision = $this->indexed();
        foreach (['declared model context limit' => ['model_info' => ['qwen2.context_length' => 4096]],
            'completion support' => ['capabilities' => ['embedding']], 'model_info' => ['model_info' => []],
            'Invalid JSON object' => ['error' => 'PRIVATE_SOURCE'], 'prompt template' => ['template' => null]] as $expected => $override) {
            $this->resetHttp();
            Http::fake(['*/api/show' => Http::response(array_replace($this->metadata(), $override))]);
            $this->assertSame(1, Artisan::call('thesis:ask', ['revisionId' => $revision->id, 'question' => 'answer']));
            $this->assertStringContainsString($expected, Artisan::output());
            Http::assertNotSent(fn (Request $r): bool => str_ends_with($r->url(), '/api/generate'));
        }
        $this->resetHttp();
        Http::fake(['*/api/show' => Http::response(['model_info' => ['general.architecture' => 'qwen2'], 'template' => '{{ .Prompt }}']), '*/api/generate' => Http::response(self::reply())]);
        $result = app(OllamaInference::class)->ask($revision->id, 'answer', 'keyword', 'qwen2.5-coder:1.5b');
        $this->assertNull($result['model_metadata']['declared_context_limit']);
        $this->assertSame('unknown', $result['model_metadata']['context_limit_status']);
    }

    public function test_invalid_revision_method_question_model_and_config_fail_before_http(): void
    {
        $revision = $this->indexed();
        Http::fake([]);
        $base = ['revisionId' => $revision->id, 'question' => 'answer'];
        foreach ([['revisionId' => 'bad'], ['revisionId' => 2147483647], ['question' => ' '], ['--method' => 'invalid'], ['--model' => ''], ['--model' => 'qwen2.5-coder:3b']] as $override) {
            $this->assertSame(1, Artisan::call('thesis:ask', array_replace($base, $override)));
            $this->assertArrayHasKey('error', json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR));
        }
        foreach (['thesis.inference.timeout_seconds' => 0, 'thesis.generation.seed' => -1,
            'thesis.generation.temperature' => -0.1, 'thesis.generation.top_p' => 2, 'services.ollama.url' => 'invalid'] as $key => $invalid) {
            $before = config($key);
            config([$key => $invalid]);
            $this->assertSame(1, Artisan::call('thesis:ask', $base));
            config([$key => $before]);
        }
        config(['thesis.models' => ['qwen2.5-coder:1.5b']]);
        $this->assertSame(1, Artisan::call('thesis:ask', [...$base, '--model' => 'qwen2.5-coder:7b']));
        Http::assertNothingSent();
    }

    private function fakeSuccess(string $model = 'qwen2.5-coder:1.5b', mixed $body = null): void
    {
        $this->resetHttp();
        Http::fake(['*/api/show' => Http::response($this->metadata()), '*/api/generate' => Http::response($body ?? self::reply($model))]);
    }

    private function resetHttp(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    /** @return array<string, mixed> */
    private function metadata(): array
    {
        return ['model_info' => ['qwen2.context_length' => 32768], 'capabilities' => ['completion'], 'details' => ['parameter_size' => '1.5B'],
            'template' => '{{ if .System }}{{ .System }}{{ end }}{{ .Prompt }}{{ .Response }}', 'system' => 'You are a helpful assistant.'];
    }

    /** @return array<string, mixed> */
    private static function reply(string $model = 'qwen2.5-coder:1.5b'): array
    {
        return ['model' => $model, 'done' => true, 'done_reason' => 'stop', 'response' => 'answer.php returns 42.',
            'prompt_eval_count' => 50, 'eval_count' => 20, 'total_duration' => 1_000_000_000,
            'load_duration' => 200_000_000, 'prompt_eval_duration' => 300_000_000, 'eval_duration' => 500_000_000];
    }

    private function indexed(): ProjectRevision
    {
        $project = User::factory()->create()->projects()->create(['name' => 'Inference fixture', 'slug' => 'inference-fixture']);
        $source = $project->sources()->create(['name' => 'Fixture', 'type' => 'local']);
        $revision = new ProjectRevision(['identifier' => 'inference-fixture']);
        $revision->project()->associate($project);
        $source->revisions()->save($revision);
        $path = 'projects/'.$project->id.'/revisions/'.$revision->id;
        $disk = Storage::disk('repository_snapshots');
        $disk->put($path.'/answer.php', "<?php\nfunction answer(): int\n{\n    return 42;\n}\n");
        $revision->update(['metadata' => ['snapshot' => ['disk' => 'repository_snapshots', 'path' => $path]]]);
        $root = $disk->path($path);
        app(RepositoryInventoryScanner::class)->scan(new ResolvedRepositorySnapshot($revision, $root, 'fixture', false, []));
        $analysis = app(PhpRevisionAnalyzer::class)->analyze($revision, $root);
        $run = new IndexRun(['status' => 'completed', 'trigger' => 'manual', 'statistics' => ['php_files_analyzed' => $analysis->filesAnalyzed]]);
        $run->project()->associate($project);
        $revision->indexRuns()->save($run);
        app(BuildRevisionChunks::class)->handle($revision->id);

        return $revision;
    }
}
