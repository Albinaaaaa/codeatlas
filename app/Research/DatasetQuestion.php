<?php

namespace App\Research;

final readonly class DatasetQuestion
{
    /** @param array<string, mixed> $evaluation */
    public function __construct(
        public string $datasetId,
        public string $datasetVersion,
        public string $split,
        public string $datasetSha256,
        public string $id,
        public int $revisionId,
        public string $fingerprint,
        public string $question,
        private array $evaluation,
    ) {}

    /** @return array{revisionId: int, question: string, method: string, model: string} */
    public function inferenceArguments(string $method, string $model): array
    {
        return ['revisionId' => $this->revisionId, 'question' => $this->question, 'method' => $method, 'model' => $model];
    }

    /** @return array<string, mixed> */
    public function evaluationData(): array
    {
        return $this->evaluation;
    }
}
