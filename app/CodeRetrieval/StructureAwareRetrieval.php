<?php

namespace App\CodeRetrieval;

use App\Models\CodeChunk;
use Illuminate\Support\Facades\DB;

/** @phpstan-import-type Candidate from RetrievalStrategy */
final class StructureAwareRetrieval implements RetrievalStrategy
{
    public const SEED_LIMIT = 5;

    public const MAX_DEPTH = 2;

    public const MAX_ADDITIONAL = 20;

    public function __construct(private readonly RevisionChunks $chunks, private readonly KeywordRetrieval $keyword) {}

    /** @return list<Candidate> */
    public function retrieve(int $revisionId, string $question): array
    {
        $chunks = $this->chunks->load($revisionId, $question);
        $seeds = array_slice($this->keyword->matches($chunks, $question), 0, self::SEED_LIMIT);
        if ($seeds === []) {
            return [];
        }
        $byId = [];
        foreach ($chunks as $chunk) {
            $byId[$this->chunks->id($chunk)] = $chunk;
        }
        $result = [];
        $frontier = [];
        foreach ($seeds as $seed) {
            $id = $this->chunks->id($seed['chunk']);
            $result[$id] = $this->chunks->candidate($seed['chunk'], count($result) + 1, $seed['score'], 'keyword seed: '.implode(', ', $seed['terms']));
            $frontier[] = $id;
        }
        $graph = $this->graph($chunks);
        $additional = 0;
        for ($depth = 1; $depth <= self::MAX_DEPTH; $depth++) {
            $next = [];
            foreach ($frontier as $from) {
                $neighbors = $graph[$from] ?? [];
                uksort($neighbors, fn (string $a, string $b): int => $this->chunks->order($byId[$a]) <=> $this->chunks->order($byId[$b]));
                foreach ($neighbors as $to => $types) {
                    if (isset($result[$to])) {
                        continue;
                    }
                    sort($types, SORT_STRING);
                    $result[$to] = $this->chunks->candidate($byId[$to], count($result) + 1, null,
                        "structure: {$types[0]}; via {$from}; depth {$depth}");
                    $next[] = $to;
                    if (++$additional >= self::MAX_ADDITIONAL) {
                        return array_values($result);
                    }
                }
            }
            $frontier = $next;
        }

        return array_values($result);
    }

    /**
     * @param  list<CodeChunk>  $chunks
     * @return array<string, array<string, list<string>>>
     */
    private function graph(array $chunks): array
    {
        $first = $chunks[0];
        $scope = ['project_id' => $first->project_id, 'project_revision_id' => $first->project_revision_id];
        $symbols = DB::table('code_symbols')->where($scope)->get()->keyBy('id');
        $bySymbol = [];
        foreach ($chunks as $chunk) {
            $symbolId = (int) $chunk->code_symbol_id;
            if ($symbols->has($symbolId)) {
                $bySymbol[$symbolId][] = $this->chunks->id($chunk);
            }
        }
        $graph = [];
        $connect = static function (array $left, array $right, string $type) use (&$graph): void {
            foreach ($left as $a) {
                foreach ($right as $b) {
                    if ($a !== $b) {
                        $graph[$a][$b][] = $type;
                        $graph[$b][$a][] = $type;
                    }
                }
            }
        };
        $link = static function (int $left, int $right, string $type) use ($bySymbol, $connect): void {
            $connect($bySymbol[$left] ?? [], $bySymbol[$right] ?? [], $type);
        };
        foreach ($symbols as $symbol) {
            $parent = $symbols->get((int) $symbol->parent_symbol_id);
            if ($symbol->kind === 'method' && $parent !== null && in_array($parent->kind, ['class', 'interface', 'trait', 'enum'], true)) {
                $link((int) $symbol->id, (int) $parent->id, 'class_method');
            }
        }
        foreach (DB::table('code_relations')->where($scope)->whereIn('type', ['extends', 'implements', 'trait_use'])->whereNotNull('to_symbol_id')->get() as $relation) {
            $link((int) $relation->from_symbol_id, (int) $relation->to_symbol_id, $relation->type);
        }
        $models = DB::table('laravel_models')->where($scope)->get()->keyBy('id');
        foreach (DB::table('laravel_model_relations')->where($scope)->whereNotNull('related_laravel_model_id')->get() as $relation) {
            $left = $models->get((int) $relation->laravel_model_id);
            $right = $models->get((int) $relation->related_laravel_model_id);
            if ($left !== null && $right !== null) {
                $link((int) $left->code_symbol_id, (int) $right->code_symbol_id, 'model:'.$relation->relation_type);
            }
        }
        $byFile = [];
        foreach ($chunks as $chunk) {
            $byFile[(int) $chunk->code_file_id][] = $chunk;
        }
        foreach (DB::table('laravel_routes')->where($scope)->whereNotNull('controller_symbol_id')->get() as $route) {
            $metadata = is_string($route->metadata) ? json_decode($route->metadata, true) : [];
            $methodId = is_array($metadata) ? ($metadata['controller_method_symbol_id'] ?? null) : null;
            $target = (int) $route->controller_symbol_id;
            if (is_int($methodId)) {
                $method = $symbols->get($methodId);
                if ($method === null || $method->kind !== 'method' || (int) $method->parent_symbol_id !== $target) {
                    continue;
                }
                $target = $methodId;
            }
            if ($route->start_line === null || $route->end_line === null) {
                continue;
            }
            foreach ($byFile[(int) $route->code_file_id] ?? [] as $chunk) {
                if ($chunk->start_line <= $route->end_line && $chunk->end_line >= $route->start_line) {
                    $connect([$this->chunks->id($chunk)], $bySymbol[$target] ?? [], 'route_controller');
                }
            }
        }

        return $graph;
    }
}
