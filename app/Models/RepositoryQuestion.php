<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property list<array{source: string, content: string}>|null $source_context
 * @property list<array<string, mixed>>|null $source_chunks
 */
class RepositoryQuestion extends Model
{
    protected $hidden = ['question', 'answer', 'source_context', 'source_chunks'];

    /** @var list<string> */
    protected $fillable = [
        'public_id', 'user_id', 'project_id', 'project_revision_id', 'revision_fingerprint', 'question',
        'requested_model', 'retrieval_method', 'status', 'answer', 'source_context', 'source_chunks', 'prompt_sha256',
        'estimated_prompt_tokens', 'actual_input_tokens', 'actual_output_tokens', 'inference_duration_ns',
        'inference_wall_ms', 'completion_status', 'stop_reason', 'safe_error', 'idempotency_key', 'request_sha256',
        'queued_at', 'started_at', 'heartbeat_at', 'completed_at',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<ProjectRevision, $this> */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(ProjectRevision::class, 'project_revision_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'question' => 'encrypted',
            'answer' => 'encrypted',
            'source_context' => 'encrypted:array',
            'source_chunks' => 'encrypted:array',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'completed_at' => 'datetime',
            'estimated_prompt_tokens' => 'integer',
            'actual_input_tokens' => 'integer',
            'actual_output_tokens' => 'integer',
            'inference_duration_ns' => 'integer',
            'inference_wall_ms' => 'decimal:3',
        ];
    }
}
