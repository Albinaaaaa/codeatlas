<?php

namespace App\Inference;

use App\ContextBuilding\BuildContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

final class OllamaInference
{
    private const MODELS = ['qwen2.5-coder:1.5b', 'qwen2.5-coder:7b', 'qwen2.5-coder:14b'];

    public function __construct(private readonly BuildContext $builder) {}

    /** @return array<string, mixed> */
    public function prepare(int $revisionId, #[\SensitiveParameter] string $question, string $method, string $model): array
    {
        $started = hrtime(true);
        if (! in_array($model, self::MODELS, true)) {
            throw new InvalidArgumentException('Supported models: qwen2.5-coder:1.5b, qwen2.5-coder:7b, qwen2.5-coder:14b.');
        }
        $url = config('services.ollama.url');
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)
            || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new InvalidArgumentException('Invalid OLLAMA_URL. Configure an HTTP(S) Ollama endpoint.');
        }
        $timeout = $this->positive('thesis.inference.timeout_seconds');
        $connectTimeout = $this->positive('thesis.inference.connect_timeout_seconds');
        $contextStarted = hrtime(true);
        $context = $this->builder->build($revisionId, $question, $method, $model);
        $contextMs = $this->elapsed($contextStarted);
        $options = [
            'temperature' => $this->number('thesis.generation.temperature', 0),
            'seed' => $this->integer('thesis.generation.seed', 0),
            'top_k' => $this->positive('thesis.generation.top_k'),
            'top_p' => $this->number('thesis.generation.top_p', 0, 1),
            'repeat_penalty' => $this->number('thesis.generation.repeat_penalty', 0.000001),
            'num_ctx' => $context['limits']['context_window_tokens'],
            'num_predict' => $context['limits']['max_output_tokens'],
        ];
        $configuration = config('thesis');
        $attempt = ['context' => $context, 'generation_options' => $options, 'protocol_configuration' => $configuration,
            'model_metadata' => [], 'submission_attempted' => false, 'stage' => 'model_inspection',
            'request' => ['stream' => false, 'raw' => false, 'timeout_seconds' => $timeout, 'connect_timeout_seconds' => $connectTimeout],
            'timings_ms' => ['context_construction' => $contextMs, 'inference_wall' => null, 'total_wall' => null], '_started' => $started];

        return $attempt;
    }

    /** @return array<string, mixed> */
    public function ask(int $revisionId, #[\SensitiveParameter] string $question, string $method, string $model): array
    {
        $attempt = $this->prepare($revisionId, $question, $method, $model);
        $context = $attempt['context'];
        $options = $attempt['generation_options'];
        $configuration = $attempt['protocol_configuration'];
        $started = $attempt['_started'];
        $contextMs = $attempt['timings_ms']['context_construction'];
        $timeout = $attempt['request']['timeout_seconds'];
        $connectTimeout = $attempt['request']['connect_timeout_seconds'];
        $url = config('services.ollama.url');
        $client = Http::baseUrl(rtrim($url, '/'))->acceptJson()->connectTimeout($connectTimeout)->withoutRedirecting();
        try {
            $inspection = $client->timeout(min(10, $timeout))->post('/api/show', ['model' => $model]);
        } catch (ConnectionException) {
            // Do not retain transport exceptions: they can contain endpoint or request details.
            throw $this->failure('Ollama model inspection failed: connection failure or timeout.', $attempt);
        }
        $metadata = $this->object($inspection, 'model inspection', $attempt);
        $template = $metadata['template'] ?? null;
        $system = $metadata['system'] ?? null;
        if (! is_string($template) || trim($template) === '' || ($system !== null && ! is_string($system))) {
            throw $this->failure('Invalid Ollama model metadata: prompt template or system metadata is unavailable or malformed.', $attempt);
        }
        $info = $metadata['model_info'] ?? null;
        if (! is_array($info) || $info === []) {
            throw $this->failure('Invalid Ollama model metadata: model_info is unavailable.', $attempt);
        }
        $capabilities = $metadata['capabilities'] ?? null;
        if ($capabilities !== null && (! is_array($capabilities) || ! in_array('completion', $capabilities, true))) {
            throw $this->failure('The requested model does not advertise completion support.', $attempt);
        }
        $limits = [];
        foreach ($info as $key => $value) {
            if (str_ends_with((string) $key, '.context_length') && is_int($value) && $value > 0) {
                $limits[] = $value;
            }
        }
        $declaredLimit = $limits === [] ? null : min($limits);
        $modelMetadata = ['declared_context_limit' => $declaredLimit,
            'context_limit_status' => $declaredLimit === null ? 'unknown' : 'validated_against_requested_num_ctx', 'capabilities' => $capabilities,
            'template_sha256' => hash('sha256', $template), 'system_sha256' => $system === null ? null : hash('sha256', $system)];
        $attempt['model_metadata'] = $modelMetadata;
        if ($declaredLimit !== null && $options['num_ctx'] > $declaredLimit) {
            throw $this->failure('Requested num_ctx exceeds the declared model context limit; no automatic reduction or model substitution is allowed.', $attempt);
        }
        $payload = ['model' => $model, 'prompt' => $context['prompt'], 'stream' => false, 'raw' => false, 'options' => $options];
        $generationStarted = hrtime(true);
        $attempt['submission_attempted'] = true;
        $attempt['stage'] = 'generation';
        $attempt['_generation_started'] = $generationStarted;
        try {
            $response = $client->timeout($timeout)->post('/api/generate', $payload);
        } catch (ConnectionException) {
            throw $this->failure('Ollama generation failed: connection failure or timeout.', $attempt);
        }
        $inferenceMs = $this->elapsed($generationStarted);
        $data = $this->object($response, 'generation', $attempt);
        $attempt['response'] = array_intersect_key($data, array_flip(['model', 'done', 'done_reason', 'response',
            'prompt_eval_count', 'eval_count', 'total_duration', 'load_duration', 'prompt_eval_duration', 'eval_duration']));
        if (($data['done'] ?? null) !== true || ! is_string($data['model'] ?? null)
            || ! is_string($data['response'] ?? null)) {
            throw $this->failure('Incomplete or malformed Ollama generation response.', $attempt);
        }
        if ($data['model'] !== $model) {
            throw $this->failure('Returned model does not match the requested model.', $attempt);
        }
        if (trim($data['response']) === '') {
            throw $this->failure('Ollama returned an empty answer.', $attempt);
        }
        $reason = $data['done_reason'] ?? null;
        if ($reason !== null && ! is_string($reason)) {
            throw $this->failure('Invalid Ollama completion reason.', $attempt);
        }
        $status = match ($reason) {
            'stop' => 'complete', 'length' => 'truncated', default => 'unknown',
        };
        $metrics = [];
        $unavailable = [];
        foreach (['prompt_eval_count', 'eval_count', 'total_duration', 'load_duration', 'prompt_eval_duration', 'eval_duration'] as $field) {
            $value = $data[$field] ?? null;
            if ($value !== null && (! is_int($value) || $value < 0)) {
                throw $this->failure('Invalid Ollama metric: '.$field.'.', $attempt);
            }
            $metrics[$field] = $value;
            if ($value === null) {
                $unavailable[] = $field;
            }
        }
        $actual = $metrics['prompt_eval_count'];
        $estimated = $context['token_usage']['prompt_tokens'];
        $actualFits = $actual === null ? null : $actual + $options['num_predict'] <= $options['num_ctx'];

        return ['requested_model' => $model, 'returned_model' => $data['model'],
            'revision_id' => $context['revision_id'], 'revision_identifier' => $context['revision_identifier'], 'revision_fingerprint' => $context['revision_fingerprint'],
            'method' => $method, 'question' => $question, 'protocol_version' => $context['protocol_version'],
            'prompt_template_version' => $context['prompt_template_version'], 'prompt' => $context['prompt'], 'prompt_hash' => $context['prompt_hash'],
            'answer' => $data['response'], 'selected_chunks' => $context['selected_chunks'], 'rejected_chunks' => $context['rejected_chunks'],
            'selected_context' => $context['selected_context'], 'protocol_configuration' => $configuration, 'submission_attempted' => true,
            'estimated_token_usage' => $context['token_usage'], 'actual_metrics' => $metrics, 'metrics_unavailable' => $unavailable,
            'duration_unit' => 'nanoseconds',
            'timings_ms' => ['context_construction' => $contextMs, 'inference_wall' => $inferenceMs, 'total_wall' => $this->elapsed($started)],
            'token_comparison' => ['estimated_prompt_tokens' => $estimated, 'actual_prompt_tokens' => $actual,
                'estimated_minus_actual' => $actual === null ? null : $estimated - $actual,
                'actual_prompt_plus_output_reserve_fits_num_ctx' => $actualFits],
            'completion' => ['status' => $status, 'done' => true, 'stop_reason' => $reason],
            'generation_options' => $options, 'context_limits' => $context['limits'],
            'request' => $attempt['request'], 'model_metadata' => $modelMetadata];
    }

    /** @param array<string, mixed> $attempt
     * @return array<string, mixed>
     */
    private function object(#[\SensitiveParameter] Response $response, string $stage, #[\SensitiveParameter] array $attempt): array
    {
        if (! $response->successful()) {
            throw $this->failure('Ollama '.$stage.' returned HTTP '.$response->status().'. Verify service availability and that the requested model is installed.', $attempt);
        }
        $data = $response->json();
        if (! is_array($data) || array_is_list($data) || array_key_exists('error', $data)) {
            throw $this->failure('Invalid JSON object or error response from Ollama '.$stage.'.', $attempt);
        }

        return $data;
    }

    /** @param array<string, mixed> $attempt */
    private function failure(string $message, #[\SensitiveParameter] array $attempt): OllamaInferenceException
    {
        $attempt['timings_ms']['total_wall'] = $this->elapsed($attempt['_started']);
        if (isset($attempt['_generation_started'])) {
            $attempt['timings_ms']['inference_wall'] = $this->elapsed($attempt['_generation_started']);
        }
        unset($attempt['_started'], $attempt['_generation_started']);

        return new OllamaInferenceException($message, $attempt);
    }

    private function positive(string $key): int
    {
        return $this->integer($key, 1);
    }

    private function integer(string $key, int $minimum): int
    {
        $value = config($key);
        if (! is_int($value) || $value < $minimum) {
            throw new InvalidArgumentException($key.' must be an integer greater than or equal to '.$minimum.'.');
        }

        return $value;
    }

    private function number(string $key, float $minimum, ?float $maximum = null): int|float
    {
        $value = config($key);
        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)
            || $value < $minimum || ($maximum !== null && $value > $maximum)) {
            throw new InvalidArgumentException('Invalid generation option: '.$key.'.');
        }

        return $value;
    }

    private function elapsed(int $started): float
    {
        return round((hrtime(true) - $started) / 1_000_000, 3);
    }
}
