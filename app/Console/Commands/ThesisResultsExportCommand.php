<?php

namespace App\Console\Commands;

use App\Research\ExportResults;
use App\Research\ResearchFiles;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class ThesisResultsExportCommand extends Command
{
    protected $signature = 'thesis:results:export {experimentId} {datasetPath?} {--repetitions= : Required for a zero-attempt campaign with a CLI repetition override} {--by-category} {--raw-only}';

    protected $description = 'Export original experiment records and explicit-denominator summaries to protected JSON, JSONL and CSV';

    public function handle(ExportResults $exporter): int
    {
        try {
            $repetitions = $this->option('repetitions');
            if ($repetitions !== null) {
                $repetitions = filter_var($repetitions, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($repetitions === false) {
                    throw new InvalidArgumentException('Repetitions must be a positive integer.');
                }
            }
            $result = $exporter->export($this->argument('experimentId'), $this->argument('datasetPath'), $repetitions,
                (bool) $this->option('by-category'), (bool) $this->option('raw-only'));
        } catch (InvalidArgumentException $error) {
            $result = ['error' => $error->getMessage()];
        } catch (Throwable) {
            $result = ['error' => 'Results export failed. Check private storage, the encryption key and saved records. No inference records were changed.'];
        }
        $this->output->writeln(json_encode($result, ResearchFiles::JSON_FLAGS | JSON_PRETTY_PRINT), OutputInterface::OUTPUT_RAW);

        return isset($result['error']) ? self::FAILURE : self::SUCCESS;
    }
}
