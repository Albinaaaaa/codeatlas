<?php

namespace App\Console\Commands;

use App\Research\RunExperiments;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class ThesisRunCommand extends Command
{
    protected $signature = 'thesis:run {datasetPath} {--repetitions= : Defaults to thesis.repetitions} {--dry-run} {--limit= : Maximum actual attempts in this invocation} {--resume : Skip successful cases with matching verified model provenance}';

    protected $description = 'Run the sequential thesis matrix against a validated private JSONL dataset';

    public function handle(RunExperiments $runner): int
    {
        $interrupted = false;
        if (defined('SIGINT') && defined('SIGTERM')) {
            $this->trap([SIGINT, SIGTERM], function () use (&$interrupted): void {
                $interrupted = true;
            });
        }
        try {
            $repetitions = $this->positive($this->option('repetitions') ?? config('thesis.repetitions'));
            $limit = $this->option('limit') === null ? null : $this->positive($this->option('limit'));
            $result = $runner->run($this->argument('datasetPath'), $repetitions, (bool) $this->option('dry-run'), $limit,
                (bool) $this->option('resume'), function (array $progress): void {
                    $this->output->getErrorStyle()->writeln(json_encode(['progress' => $progress], JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
                }, function () use (&$interrupted): bool {
                    return $interrupted;
                });
            $this->output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

            return $result['interrupted'] ? 130 : ($result['dry_run'] || $result['remaining'] === 0 ? self::SUCCESS : 2);
        } catch (InvalidArgumentException $error) {
            $this->output->writeln(json_encode(['error' => $error->getMessage()], JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

            return self::FAILURE;
        } catch (Throwable) {
            // Do not print exception traces, HTTP bodies or SQL containing private payloads.
            $this->output->writeln('{"error":"Experiment stopped unexpectedly. Saved attempts remain durable; use --resume after resolving the failure."}', OutputInterface::OUTPUT_RAW);

            return self::FAILURE;
        } finally {
            $this->untrap();
        }
    }

    private function positive(mixed $value): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($integer === false) {
            throw new InvalidArgumentException('Repetitions and limit must be positive integers.');
        }

        return $integer;
    }
}
