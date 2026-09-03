<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectRevision extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'identifier',
        'branch',
        'commit_hash',
        'message',
        'author_name',
        'author_email',
        'committed_at',
        'metadata',
    ];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<ProjectSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(ProjectSource::class, 'project_source_id');
    }

    /** @return HasMany<IndexRun, $this> */
    public function indexRuns(): HasMany
    {
        return $this->hasMany(IndexRun::class);
    }

    /** @return HasMany<CodeFile, $this> */
    public function codeFiles(): HasMany
    {
        return $this->hasMany(CodeFile::class);
    }

    /** @return HasMany<AnalysisIssue, $this> */
    public function analysisIssues(): HasMany
    {
        return $this->hasMany(AnalysisIssue::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'committed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
