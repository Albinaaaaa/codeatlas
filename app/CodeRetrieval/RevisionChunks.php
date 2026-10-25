<?php

namespace App\CodeRetrieval;

use App\Models\CodeChunk;
use App\Models\ProjectRevision;
use InvalidArgumentException;

/** @phpstan-import-type Candidate from RetrievalStrategy */
final class RevisionChunks
{
    /** @return list<CodeChunk> */
    public function load(int $revisionId, string $question): array
    {
        if ($revisionId < 1 || trim($question) === '') {
            throw new InvalidArgumentException('A positive revision ID and a non-empty question are required.');
        }
        $revision = ProjectRevision::query()->find($revisionId);
        if ($revision === null) {
            throw new InvalidArgumentException('Project revision does not exist.');
        }
        $chunks = CodeChunk::query()->where('project_id', $revision->project_id)
            ->where('project_revision_id', $revision->id)
            ->select(['id', 'project_id', 'project_revision_id', 'code_file_id', 'code_symbol_id', 'content', 'start_line', 'end_line', 'metadata'])
            ->with(['codeFile', 'codeSymbol'])->get()->all();
        if ($chunks === []) {
            throw new InvalidArgumentException('Revision has no generated chunks. Run thesis:build-chunks explicitly first.');
        }
        foreach ($chunks as $chunk) {
            $chunk->setRelation('revision', $revision);
            if ($chunk->start_line === null || $chunk->end_line === null || $chunk->start_line < 1 || $chunk->end_line < $chunk->start_line) {
                throw new InvalidArgumentException('Chunk source ranges are invalid; rebuild chunks explicitly.');
            }
        }
        usort($chunks, fn (CodeChunk $a, CodeChunk $b): int => $this->order($a) <=> $this->order($b));

        return $chunks;
    }

    public function id(CodeChunk $chunk): string
    {
        $metadata = $chunk->getAttribute('metadata');
        $id = is_array($metadata) ? ($metadata['stable_id'] ?? null) : null;

        return is_string($id) && $id !== '' ? $id : hash('sha256', json_encode([
            'retrieval-fallback-v1', $chunk->codeFile->path, $chunk->start_line, $chunk->end_line,
            hash('sha256', $chunk->content),
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array{string, int, string} */
    public function order(CodeChunk $chunk): array
    {
        return [$chunk->codeFile->path, (int) $chunk->start_line, $this->id($chunk)];
    }

    /** @return Candidate */
    public function candidate(CodeChunk $chunk, int $rank, ?int $score, string $reason): array
    {
        return ['chunk_id' => $this->id($chunk), 'revision_id' => (int) $chunk->project_revision_id,
            'revision_identifier' => $chunk->revision->identifier, 'path' => $chunk->codeFile->path,
            'start_line' => (int) $chunk->start_line, 'end_line' => (int) $chunk->end_line,
            'rank' => $rank, 'score' => $score, 'reason' => $reason];
    }
}
