<?php

namespace Tests\Unit;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

class ThesisPreflightTest extends TestCase
{
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        // These HTTP-only tests must never connect to any database, even with cached config.
        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
            $app['config']->set('database.default', 'disabled');
            $app['config']->set('database.connections', []);
            $app['config']->set('cache.default', 'array');
            $app['config']->set('session.driver', 'array');
            $app['config']->set('services.ollama.url', 'http://ollama.test:11434');
            $app['config']->set('thesis', require __DIR__.'/../../config/thesis.php');
        });
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_protocol_defines_nine_combinations_and_explicitly_incomplete_dataset(): void
    {
        $this->assertTrue($this->app->environment('testing'));
        $this->assertSame([], config('database.connections'));
        $this->assertSame('1.0.0', config('thesis.protocol_version'));
        $this->assertSame(['qwen2.5-coder:1.5b', 'qwen2.5-coder:7b', 'qwen2.5-coder:14b'], config('thesis.models'));
        $this->assertSame(['baseline', 'keyword', 'structure_aware'], config('thesis.methods'));
        $this->assertSame('incomplete', config('thesis.status'));
        $this->assertNull(config('thesis.dataset.id'));
        $this->assertSame([], config('thesis.dataset.repositories'));
        foreach (['context_token_budget', 'max_output_tokens', 'context_window_tokens', 'repetitions'] as $key) {
            $this->assertGreaterThan(0, config('thesis.'.$key));
        }
        $this->assertLessThan(config('thesis.context_window_tokens'), config('thesis.context_token_budget') + config('thesis.max_output_tokens'));
        $this->assertStringContainsString('{{context}}', config('thesis.prompt.template'));
        $this->assertStringContainsString('{{question}}', config('thesis.prompt.template'));
        $this->assertSame(42, config('thesis.generation.seed'));
        $this->assertContains('answer_accuracy', config('thesis.metrics'));
        $this->assertContains('retrieval_recall', config('thesis.metrics'));
        $this->assertContains('total_ms', config('thesis.metrics'));
    }

    public function test_all_models_available_without_inference(): void
    {
        $this->fakeModels(config('thesis.models'));
        $this->artisan('thesis:preflight')
            ->expectsOutputToContain('AVAILABLE: qwen2.5-coder:14b')
            ->expectsOutputToContain('inference has not been tested')
            ->expectsOutputToContain('8192')
            ->assertSuccessful();
        Http::assertSentCount(4);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/generate') || str_contains($request->url(), '/api/pull'));
    }

    public function test_missing_model_fails(): void
    {
        $this->fakeModels(['qwen2.5-coder:1.5b']);
        $this->artisan('thesis:preflight')->expectsOutputToContain('MISSING: qwen2.5-coder:7b')->assertFailed();
        Http::assertSentCount(2);
    }

    public function test_unavailable_ollama_fails_without_leaking_connection_details(): void
    {
        Http::fake(['*' => Http::failedConnection()]);
        $this->artisan('thesis:preflight')->expectsOutputToContain('Cannot connect to Ollama')->assertFailed();
    }

    #[DataProvider('invalidResponses')]
    public function test_invalid_tags_fail(mixed $body, int $status): void
    {
        Http::fake(['*' => Http::response($body, $status)]);
        $this->artisan('thesis:preflight')->assertFailed();
        Http::assertSentCount(1);
    }

    public static function invalidResponses(): array
    {
        return [
            'http error' => [[], 503],
            'not json' => ['not json', 200],
            'missing list' => [[], 200],
            'wrong list type' => [['models' => 'invalid'], 200],
            'missing name' => [['models' => [['digest' => 'abc']]], 200],
            'invalid entry' => [['models' => [null]], 200],
            'no models installed' => [['models' => []], 200],
        ];
    }

    public function test_incomplete_metadata_fails(): void
    {
        $this->fakeModels(config('thesis.models'), []);
        $this->artisan('thesis:preflight')->expectsOutputToContain('Invalid or unavailable metadata')->assertFailed();
    }

    public function test_metadata_connection_failure_continues_checking_other_models(): void
    {
        Http::fake([
            '*/api/tags' => Http::response(['models' => array_map(fn ($name) => ['name' => $name], config('thesis.models'))]),
            '*/api/show' => Http::failedConnection(),
        ]);
        $this->artisan('thesis:preflight')
            ->expectsOutputToContain('Metadata connection failure or timeout: qwen2.5-coder:1.5b')
            ->expectsOutputToContain('Metadata connection failure or timeout: qwen2.5-coder:14b')
            ->assertFailed();
    }

    public function test_invalid_endpoint_fails_without_network_access(): void
    {
        config(['services.ollama.url' => 'not-a-url']);
        Http::fake();
        $this->artisan('thesis:preflight')->expectsOutputToContain('Invalid OLLAMA_URL')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_context_window_exceeding_model_limit_fails(): void
    {
        $this->fakeModels(config('thesis.models'));
        config(['thesis.context_window_tokens' => 65536]);
        $this->artisan('thesis:preflight')->expectsOutputToContain('exceeds the declared model context limit')->assertFailed();
    }

    private function fakeModels(array $names, ?array $metadata = null): void
    {
        Http::fake([
            '*/api/tags' => Http::response(['models' => array_map(fn ($name) => ['name' => $name, 'digest' => 'test-digest'], $names)]),
            '*/api/show' => Http::response($metadata ?? [
                'model_info' => ['qwen2.context_length' => 32768],
                'details' => ['parameter_size' => '7.6B', 'quantization_level' => 'Q4_K_M'],
                'parameters' => "num_ctx 8192\n",
            ]),
        ]);
    }
}
