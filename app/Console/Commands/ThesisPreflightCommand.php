<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class ThesisPreflightCommand extends Command
{
    protected $signature = 'thesis:preflight';

    protected $description = 'Check required Ollama models and metadata without running inference';

    public function handle(): int
    {
        $url = config('services.ollama.url');

        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)
            || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            $this->error('Invalid OLLAMA_URL. Configure an HTTP(S) Ollama endpoint.');

            return self::FAILURE;
        }

        $client = Http::baseUrl(rtrim($url, '/'))->acceptJson()->connectTimeout(3)->timeout(10)
            ->withoutRedirecting();

        $this->line('Protocol '.config('thesis.protocol_version').' ('.config('thesis.status').').');
        $this->line('Planned context budget: '.config('thesis.context_token_budget')
            .'; output limit: '.config('thesis.max_output_tokens')
            .'; requested num_ctx: '.config('thesis.context_window_tokens').'.');
        $this->warn('Availability only; inference has not been tested by this command. Dataset readiness is separate.');

        try {
            $response = $client->get('/api/tags');
        } catch (ConnectionException) {
            $this->error('Cannot connect to Ollama (connection failure or timeout). Check OLLAMA_URL, service status and network access.');

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->error('Ollama /api/tags returned HTTP '.$response->status().'. Check the endpoint and service.');

            return self::FAILURE;
        }

        $models = $response->json('models');
        if (! is_array($models) || ! array_is_list($models)) {
            $this->error('Invalid Ollama /api/tags response: expected a models list.');

            return self::FAILURE;
        }

        $installed = [];
        foreach ($models as $model) {
            if (! is_array($model) || ! is_string($model['name'] ?? null) || $model['name'] === '') {
                $this->error('Invalid Ollama /api/tags response: model name missing.');

                return self::FAILURE;
            }
            $installed[$model['name']] = $model;
        }

        $failed = false;
        foreach (config('thesis.models') as $name) {
            if (! isset($installed[$name])) {
                $this->error("MISSING: {$name}. Manual setup: ollama pull {$name} (on the configured server).");
                $failed = true;

                continue;
            }

            $this->info("AVAILABLE: {$name}");
            try {
                $metadata = $client->post('/api/show', ['model' => $name]);
            } catch (ConnectionException) {
                $this->error("Metadata connection failure or timeout: {$name}");
                $failed = true;

                continue;
            }

            $info = $metadata->json('model_info');
            $details = $metadata->json('details');
            if (! $metadata->successful() || ! is_array($info) || $info === [] || ! is_array($details)) {
                $this->error("Invalid or unavailable metadata: {$name} (HTTP {$metadata->status()}).");
                $failed = true;

                continue;
            }

            foreach (['parameter_size', 'quantization_level'] as $key) {
                $this->line($key.': '.(is_string($details[$key] ?? null) ? $details[$key] : 'unknown'));
            }
            $digest = $installed[$name]['digest'] ?? null;
            $this->line('digest: '.(is_string($digest) ? $digest : 'unknown'));
            $limit = null;
            foreach ($info as $key => $value) {
                if (str_ends_with((string) $key, '.context_length') && is_int($value) && $value > 0) {
                    $limit = $value;
                    $this->line("Declared model context limit ({$key}): {$value}");
                }
            }
            if ($limit === null) {
                $this->warn('Declared model context limit: unknown.');
            } elseif ($limit < config('thesis.context_window_tokens')) {
                $this->error('Planned num_ctx exceeds the declared model context limit.');
                $failed = true;
            }

            $parameters = $metadata->json('parameters');
            $configured = is_string($parameters) && preg_match('/^\s*num_ctx\s+(\d+)\s*$/m', $parameters, $matches)
                ? $matches[1] : 'not declared (server/runtime default unknown)';
            $this->line('Model num_ctx: '.$configured);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
