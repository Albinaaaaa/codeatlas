<?php

namespace App\Console\Commands;

use App\CodeRetrieval\BaselineRetrieval;
use App\CodeRetrieval\KeywordRetrieval;
use App\CodeRetrieval\StructureAwareRetrieval;
use Illuminate\Console\Command;
use InvalidArgumentException;

class ThesisRetrieveCommand extends Command
{
    protected $signature = 'thesis:retrieve {revisionId} {question} {--method=baseline}';

    protected $description = 'Inspect deterministic revision-scoped retrieval candidates as JSON';

    public function handle(BaselineRetrieval $baseline, KeywordRetrieval $keyword, StructureAwareRetrieval $structure): int
    {
        try {
            $id = filter_var($this->argument('revisionId'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $question = $this->argument('question');
            if ($id === false || trim($question) === '') {
                throw new InvalidArgumentException('A positive revision ID and a non-empty question are required.');
            }
            $method = $this->option('method');
            $strategy = match ($method) {
                'baseline' => $baseline, 'keyword' => $keyword, 'structure_aware' => $structure,
                default => throw new InvalidArgumentException('Supported methods: baseline, keyword, structure_aware.'),
            };
            $output = ['method' => $method, 'revision_id' => $id, 'question' => $question,
                'candidates' => $strategy->retrieve($id, $question)];
        } catch (InvalidArgumentException $exception) {
            $this->line(json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            return self::FAILURE;
        }
        $this->line(json_encode($output, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
