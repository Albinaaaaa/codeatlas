<?php

namespace App\ContextBuilding;

use App\CodeRetrieval\BaselineRetrieval;
use App\CodeRetrieval\KeywordRetrieval;
use App\CodeRetrieval\RevisionChunks;
use App\CodeRetrieval\StructureAwareRetrieval;
use App\Models\CodeChunk;
use App\Models\ProjectRevision;
use InvalidArgumentException;

final class BuildContext
{
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG;

    public function __construct(
        private readonly BaselineRetrieval $baseline,
        private readonly KeywordRetrieval $keyword,
        private readonly StructureAwareRetrieval $structure,
        private readonly RevisionChunks $chunks,
        private readonly ConservativeTokenEstimator $estimator,
    ) {}

    /** @return array<string, mixed> */
    public function build(int $revisionId, string $question, string $method, string $model, ?int $budget = null): array
    {
        if ($revisionId < 1 || trim($question) === '' || ! mb_check_encoding($question, 'UTF-8')) {
            throw new InvalidArgumentException('A positive revision ID and a non-empty UTF-8 question are required.');
        }
        $revision = ProjectRevision::query()->find($revisionId);
        if ($revision === null) {
            throw new InvalidArgumentException('Project revision does not exist.');
        }
        $strategy = match ($method) {
            'baseline' => $this->baseline, 'keyword' => $this->keyword, 'structure_aware' => $this->structure,
            default => throw new InvalidArgumentException('Supported methods: baseline, keyword, structure_aware.'),
        };
        $models = config('thesis.models');
        if (! is_array($models) || ! in_array($model, $models, true)) {
            throw new InvalidArgumentException('Model must be one of the configured thesis models.');
        }
        $configuredBudget = $this->positive('thesis.context_token_budget');
        $budget ??= $configuredBudget;
        if ($budget < 1) {
            throw new InvalidArgumentException('Source context budget must be a positive integer.');
        }
        $output = $this->positive('thesis.max_output_tokens');
        $window = $this->positive('thesis.context_window_tokens');
        $margin = $this->positive('thesis.token_estimation.safety_margin_percent');
        $allowance = $this->positive('thesis.token_estimation.chat_template_allowance_tokens');
        if ($margin > 100 || config('thesis.token_estimation.method') !== ConservativeTokenEstimator::METHOD) {
            throw new InvalidArgumentException('Unsupported token estimator or safety margin (expected 1–100 percent).');
        }
        $template = config('thesis.prompt.template');
        $version = config('thesis.prompt.version');
        $protocol = config('thesis.protocol_version');
        if (! is_string($template) || ! mb_check_encoding($template, 'UTF-8')
            || substr_count($template, '{{context}}') !== 1 || substr_count($template, '{{question}}') !== 1
            || ! is_string($version) || $version === '' || ! is_string($protocol) || $protocol === '') {
            throw new InvalidArgumentException('A versioned prompt template with exactly one context and question placeholder is required.');
        }
        $evidence = [];
        $prompt = $this->render($template, $question, $evidence);
        $usage = $this->usage($prompt, 0, $allowance, $margin, $output);
        if ($usage['total_reserved_tokens'] > $window) {
            throw new InvalidArgumentException('Question, prompt overhead, safety margin and reserved output exceed the model context window.');
        }
        $candidates = $strategy->retrieve($revisionId, $question);
        $byId = [];
        // Retrieval returns provenance, not source. Hydrate only candidate content in scoped batches.
        if ($candidates !== []) {
            $wanted = array_fill_keys(array_column($candidates, 'chunk_id'), true);
            $query = CodeChunk::query()->where('project_id', $revision->project_id)->where('project_revision_id', $revision->id)
                ->select(['id', 'code_file_id', 'content', 'start_line', 'end_line', 'metadata'])->with('codeFile');
            foreach (array_chunk(array_keys($wanted), 500) as $ids) {
                foreach ((clone $query)->whereIn('metadata->stable_id', $ids)->get() as $chunk) {
                    $byId[$this->chunks->id($chunk)] ??= $chunk;
                }
            }
            if (count($byId) < count($wanted)) {
                foreach ((clone $query)->where(fn ($q) => $q->whereNull('metadata->stable_id')->orWhere('metadata->stable_id', ''))->lazyById(200) as $chunk) {
                    $id = $this->chunks->id($chunk);
                    if (isset($wanted[$id])) {
                        $byId[$id] ??= $chunk;
                    }
                    if (count($byId) === count($wanted)) {
                        break;
                    }
                }
            }
        }
        $selected = [];
        $rejected = [];
        $hashes = [];
        $sourceTokens = 0;
        foreach ($candidates as $candidate) {
            $chunk = $byId[$candidate['chunk_id']] ?? null;
            if ($chunk === null || $chunk->codeFile->path !== $candidate['path']
                || $chunk->start_line !== $candidate['start_line'] || $chunk->end_line !== $candidate['end_line']) {
                throw new InvalidArgumentException('Retrieved chunks changed during context construction; retry with a stable index.');
            }
            $hash = hash('sha256', $chunk->content);
            $reason = isset($hashes[$hash]) ? 'duplicate_content' : null;
            foreach ($selected as $previous) {
                if ($reason === null && $previous['path'] === $candidate['path']
                    && $previous['start_line'] <= $candidate['end_line'] && $candidate['start_line'] <= $previous['end_line']) {
                    $reason = 'overlapping_source_range';
                }
            }
            $tokens = $this->estimator->estimate($chunk->content);
            if ($reason === null && $sourceTokens + $tokens > $budget) {
                $reason = 'source_context_budget';
            }
            if ($reason !== null) {
                $rejected[] = [...$candidate, 'rejection_reason' => $reason];

                continue;
            }
            $entry = ['source' => $candidate['path'].':'.$candidate['start_line'].'-'.$candidate['end_line'], 'content' => $chunk->content];
            $trial = $this->render($template, $question, [...$evidence, $entry]);
            $trialUsage = $this->usage($trial, $sourceTokens + $tokens, $allowance, $margin, $output);
            if ($trialUsage['total_reserved_tokens'] > $window) {
                $rejected[] = [...$candidate, 'rejection_reason' => 'model_context_window'];

                continue;
            }
            $evidence[] = $entry;
            $selected[] = [...$candidate, 'selection_reason' => 'fits_source_and_model_budgets', 'content_hash' => $hash];
            $hashes[$hash] = true;
            $sourceTokens += $tokens;
            $prompt = $trial;
            $usage = $trialUsage;
        }

        $metadata = $revision->getAttribute('metadata');
        $fingerprint = is_array($metadata) ? ($metadata['snapshot']['fingerprint'] ?? null) : null;

        return ['revision_id' => $revisionId, 'revision_identifier' => $revision->identifier, 'revision_fingerprint' => $fingerprint,
            'question' => $question, 'selected_context' => $evidence,
            'model' => $model, 'method' => $method, 'protocol_version' => $protocol, 'prompt_template_version' => $version,
            'prompt' => $prompt, 'prompt_hash' => hash('sha256', $prompt), 'selected_chunks' => $selected, 'rejected_chunks' => $rejected,
            'token_usage' => ['is_estimate' => true, 'counting_method' => ConservativeTokenEstimator::METHOD, ...$usage,
                'actual_prompt_tokens' => null, 'actual_output_tokens' => null],
            'limits' => ['configured_source_context_tokens' => $configuredBudget, 'source_context_tokens' => $budget,
                'max_output_tokens' => $output, 'context_window_tokens' => $window, 'safety_margin_percent' => $margin,
                'chat_template_allowance_tokens' => $allowance]];
    }

    private function positive(string $key): int
    {
        $value = config($key);
        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException($key.' must be a positive integer.');
        }

        return $value;
    }

    /** @param list<array{source: string, content: string}> $evidence */
    private function render(string $template, string $question, array $evidence): string
    {
        return strtr($template, ['{{context}}' => json_encode($evidence, self::JSON_FLAGS),
            '{{question}}' => json_encode($question, self::JSON_FLAGS)]);
    }

    /** @return array<string, int> */
    private function usage(string $prompt, int $source, int $allowance, int $margin, int $output): array
    {
        $text = $this->estimator->estimate($prompt);
        $estimated = $text + $allowance;
        $safety = intdiv($estimated * $margin + 99, 100);

        return ['source_tokens' => $source, 'prompt_text_tokens' => $text, 'prompt_overhead_tokens' => $text - $source,
            'chat_template_allowance_tokens' => $allowance, 'prompt_tokens' => $estimated, 'safety_margin_tokens' => $safety,
            'prompt_with_margin_tokens' => $estimated + $safety, 'reserved_output_tokens' => $output,
            'total_reserved_tokens' => $estimated + $safety + $output];
    }
}
