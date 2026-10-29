<?php

namespace App\Console\Commands;

use App\Research\ManualEvaluation;
use App\Research\ResearchFiles;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class ThesisGradeExportCommand extends Command
{
    protected $signature = 'thesis:grade:export {experimentId} {--seed= : Blind ordering seed; defaults to the configured seed}';

    protected $description = 'Export successful answers for blind manual grading to protected storage';

    public function handle(ManualEvaluation $evaluation): int
    {
        try {
            $seed = filter_var($this->option('seed') ?? config('thesis.generation.seed'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($seed === false) {
                throw new InvalidArgumentException('Blind ordering seed must be a non-negative integer.');
            }
            $result = $evaluation->export($this->argument('experimentId'), $seed);
        } catch (InvalidArgumentException $error) {
            $result = ['error' => $error->getMessage()];
        } catch (Throwable) {
            $result = ['error' => 'Blind export failed. Check protected storage and saved experiment availability.'];
        }
        $this->output->writeln(json_encode($result, ResearchFiles::JSON_FLAGS | JSON_PRETTY_PRINT), OutputInterface::OUTPUT_RAW);

        return isset($result['error']) ? self::FAILURE : self::SUCCESS;
    }
}
