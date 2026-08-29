<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IndexRun extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'status',
        'trigger',
        'started_at',
        'completed_at',
        'failure_reason',
        'statistics',
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

    /** @return HasMany<IndexRunStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(IndexRunStep::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'statistics' => 'array',
        ];
    }
}
