<?php

namespace App\Console\Commands;

use App\ContextBuilding\BuildContext;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Output\OutputInterface;

class ThesisContextCommand extends Command
{
    protected $signature = 'thesis:context {revisionId} {question} {--method=keyword} {--model=} {--budget=}';

    protected $description = 'Build a deterministic prompt with estimated token budgets, without inference';

    public function handle(BuildContext $builder): int
    {
        try {
            $id = filter_var($this->argument('revisionId'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $budgetOption = $this->option('budget');
            $budget = $budgetOption === null ? null : filter_var($budgetOption, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $model = $this->option('model') ?? config('thesis.models.0');
            $method = $this->option('method');
            if ($id === false || $budget === false || ! is_string($model) || ! is_string($method)) {
                throw new InvalidArgumentException('Revision ID and budget must be positive integers; model and method must be configured identifiers.');
            }
            $result = $builder->build($id, $this->argument('question'), $method, $model, $budget);
        } catch (InvalidArgumentException $exception) {
            $this->output->writeln(json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);

            return self::FAILURE;
        }
        $this->output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
