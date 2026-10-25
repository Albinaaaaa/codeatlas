<?php

namespace App\CodeRetrieval;

/** @phpstan-import-type Candidate from RetrievalStrategy */
final class BaselineRetrieval implements RetrievalStrategy
{
    public function __construct(private readonly RevisionChunks $chunks) {}

    /** @return list<Candidate> */
    public function retrieve(int $revisionId, string $question): array
    {
        $result = [];
        foreach ($this->chunks->load($revisionId, $question) as $chunk) {
            $id = $this->chunks->id($chunk);
            if (! isset($result[$id])) {
                $result[$id] = $this->chunks->candidate($chunk, count($result) + 1, null, 'baseline: source order');
            }
        }

        return array_values($result);
    }
}
