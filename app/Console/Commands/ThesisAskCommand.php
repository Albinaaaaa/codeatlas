<?php

namespace App\Console\Commands;

use App\Inference\OllamaInference;
use App\Inference\OllamaInferenceException;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Output\OutputInterface;

class ThesisAskCommand extends Command
{
    protected $signature = 'thesis:ask {revisionId} {question} {--method=keyword} {--model=}';

    protected $description = 'Ask a local Qwen model about an indexed revision and return answer and inference metrics';

    public function handle(OllamaInference $inference): int
    {
        try {
            $id = filter_var($this->argument('revisionId'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $model = $this->option('model') ?? config('thesis.models.0');
            $method = $this->option('method');
            if ($id === false || ! is_string($model) || ! is_string($method)) {
                throw new InvalidArgumentException('A positive revision ID, a configured model and a supported method are required.');
            }
            $result = $inference->ask($id, $this->argument('question'), $method, $model);
        } catch (InvalidArgumentException|OllamaInferenceException $exception) {
            $this->output->writeln(json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);

            return self::FAILURE;
        }
        $this->output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

        return $result['completion']['status'] === 'complete'
            && $result['token_comparison']['actual_prompt_plus_output_reserve_fits_num_ctx'] !== false ? self::SUCCESS : 2;
    }
}
