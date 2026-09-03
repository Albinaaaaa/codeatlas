<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalysisIssue extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'severity',
        'category',
        'code',
        'title',
        'description',
        'source_path',
        'start_line',
        'end_line',
        'metadata',
        'resolved_at',
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

    /** @return BelongsTo<CodeFile, $this> */
    public function codeFile(): BelongsTo
    {
        return $this->belongsTo(CodeFile::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'resolved_at' => 'datetime',
        ];
    }
}
