<?php

return [
    'protocol_version' => '1.0.0',
    'status' => 'incomplete',
    'experiment_deadline' => '2026-11-12',
    'models' => ['qwen2.5-coder:1.5b', 'qwen2.5-coder:7b', 'qwen2.5-coder:14b'],
    'methods' => ['baseline', 'keyword', 'structure_aware'],
    'context_token_budget' => (int) env('THESIS_CONTEXT_TOKEN_BUDGET', 4096),
    'max_output_tokens' => (int) env('THESIS_MAX_OUTPUT_TOKENS', 512),
    'context_window_tokens' => (int) env('THESIS_CONTEXT_WINDOW_TOKENS', 8192),
    'prompt' => [
        'version' => '1.0.0',
        'template' => "Answer the question using only the supplied repository context. Cite file paths for claims. If evidence is insufficient, state that explicitly.\n\nContext:\n{{context}}\n\nQuestion:\n{{question}}",
    ],
    'generation' => [
        'temperature' => 0.0,
        'seed' => 42,
        'top_k' => 40,
        'top_p' => 0.9,
        'repeat_penalty' => 1.1,
    ],
    'repetitions' => (int) env('THESIS_REPETITIONS', 3),
    'dataset' => [
        'id' => null,
        'version' => null,
        'sha256' => null,
        'repositories' => [],
    ],
    'metrics' => [
        'answer_accuracy', 'retrieval_precision', 'retrieval_recall',
        'prompt_tokens', 'output_tokens', 'context_construction_ms',
        'inference_ms', 'total_ms',
    ],
];
