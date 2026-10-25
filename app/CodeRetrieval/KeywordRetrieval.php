<?php

namespace App\CodeRetrieval;

use App\Models\CodeChunk;

/**
 * @phpstan-import-type Candidate from RetrievalStrategy
 *
 * @phpstan-type MatchResult array{chunk: CodeChunk, score: int, terms: list<string>}
 */
final class KeywordRetrieval implements RetrievalStrategy
{
    public function __construct(private readonly RevisionChunks $chunks) {}

    /** @return list<Candidate> */
    public function retrieve(int $revisionId, string $question): array
    {
        $result = [];
        foreach ($this->matches($this->chunks->load($revisionId, $question), $question) as $match) {
            $result[] = $this->chunks->candidate($match['chunk'], count($result) + 1, $match['score'], 'keyword: '.implode(', ', $match['terms']));
        }

        return $result;
    }

    /** @return list<string> */
    public function terms(string $text): array
    {
        $text = preg_replace('/(\p{Lu})(\p{Lu}\p{Ll})/u', '$1 $2', $text) ?? $text;
        $text = preg_replace('/([\p{Ll}\p{N}])(\p{Lu})/u', '$1 $2', $text) ?? $text;
        $terms = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $terms = array_values(array_unique($terms));
        sort($terms, SORT_STRING);

        return $terms;
    }

    /**
     * @param  list<CodeChunk>  $chunks
     * @return list<MatchResult>
     */
    public function matches(array $chunks, string $question): array
    {
        $terms = $this->terms($question);
        $matches = [];
        foreach ($chunks as $chunk) {
            $metadata = $chunk->getAttribute('metadata');
            $name = is_array($metadata) && is_string($metadata['symbol_name'] ?? null) ? $metadata['symbol_name'] : '';
            $name .= ' '.($chunk->codeSymbol->qualified_name ?? '');
            $score = 0;
            $matched = [];
            // Each distinct question term contributes at most once per field.
            foreach ([[$chunk->content, 1], [$name, 3], [$chunk->codeFile->path, 2]] as [$text, $weight]) {
                $hits = array_intersect($terms, $this->terms($text));
                $score += count($hits) * $weight;
                $matched = array_merge($matched, $hits);
            }
            $id = $this->chunks->id($chunk);
            if ($score > 0 && ! isset($matches[$id])) {
                $matched = array_values(array_unique($matched));
                sort($matched, SORT_STRING);
                $matches[$id] = ['chunk' => $chunk, 'score' => $score, 'terms' => $matched];
            }
        }
        $matches = array_values($matches);
        usort($matches, fn (array $a, array $b): int => ($b['score'] <=> $a['score'])
            ?: ($this->chunks->order($a['chunk']) <=> $this->chunks->order($b['chunk'])));

        return $matches;
    }
}
