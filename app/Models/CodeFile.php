<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CodeFile extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'path',
        'language',
        'content_hash',
        'size_bytes',
        'line_count',
        'is_generated',
        'metadata',
    ];

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
            'size_bytes' => 'integer',
            'line_count' => 'integer',
            'is_generated' => 'boolean',
            'metadata' => 'array',
        ];
    }
}
