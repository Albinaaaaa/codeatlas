<?php

namespace App\Console\Commands;

use App\Research\ManualEvaluation;
use App\Research\ResearchFiles;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class ThesisGradeImportCommand extends Command
{
    protected $signature = 'thesis:grade:import {experimentId} {path}';

    protected $description = 'Validate and atomically import manually assigned JSONL grades without changing inference records';

    public function handle(ManualEvaluation $evaluation): int
    {
        try {
            $result = $evaluation->import($this->argument('experimentId'), $this->argument('path'));
        } catch (InvalidArgumentException $error) {
            $result = ['error' => $error->getMessage()];
        } catch (Throwable) {
            $result = ['error' => 'Grade import failed; no inference records were changed. Check database availability and the application encryption key.'];
        }
        $this->output->writeln(json_encode($result, ResearchFiles::JSON_FLAGS | JSON_PRETTY_PRINT), OutputInterface::OUTPUT_RAW);

        return isset($result['error']) ? self::FAILURE : self::SUCCESS;
    }
}
