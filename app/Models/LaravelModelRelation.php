<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaravelModelRelation extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'name',
        'relation_type',
        'related_model',
        'foreign_key',
        'local_key',
        'pivot_table',
        'start_line',
        'end_line',
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

    /** @return BelongsTo<LaravelModel, $this> */
    public function model(): BelongsTo
    {
        return $this->belongsTo(LaravelModel::class, 'laravel_model_id');
    }

    /** @return BelongsTo<LaravelModel, $this> */
    public function relatedModel(): BelongsTo
    {
        return $this->belongsTo(LaravelModel::class, 'related_laravel_model_id');
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
        ];
    }
}
