<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IndexRunStep extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'name',
        'status',
        'started_at',
        'completed_at',
        'records_processed',
        'failure_reason',
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

    /** @return BelongsTo<IndexRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(IndexRun::class, 'index_run_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'records_processed' => 'integer',
            'metadata' => 'array',
        ];
    }
}
