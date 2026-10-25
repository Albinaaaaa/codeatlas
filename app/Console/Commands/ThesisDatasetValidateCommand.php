<?php

namespace App\Console\Commands;

use App\Research\DatasetValidator;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Output\OutputInterface;

class ThesisDatasetValidateCommand extends Command
{
    protected $signature = 'thesis:dataset:validate {path}';

    protected $description = 'Validate a local JSONL research dataset against immutable repository evidence';

    public function handle(DatasetValidator $validator): int
    {
        try {
            $report = $validator->validate($this->argument('path'));
            unset($report['questions']);
        } catch (InvalidArgumentException $exception) {
            $report = ['total' => 0, 'valid' => 0, 'invalid' => 0,
                'errors' => [['line' => 0, 'question_id' => null, 'message' => $exception->getMessage()]]];
        }
        $this->output->writeln(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);

        return $report['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
