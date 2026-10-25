<?php

namespace App\CodeRetrieval;

/**
 * @phpstan-type Candidate array{chunk_id: string, revision_id: int, revision_identifier: string, path: string, start_line: int, end_line: int, rank: int, score: ?int, reason: string}
 */
interface RetrievalStrategy
{
    /** @return list<Candidate> */
    public function retrieve(int $revisionId, string $question): array;
}
