<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaravelModel extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'table_name',
        'connection',
        'traits',
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

    /** @return BelongsTo<CodeSymbol, $this> */
    public function codeSymbol(): BelongsTo
    {
        return $this->belongsTo(CodeSymbol::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'traits' => 'array',
            'metadata' => 'array',
        ];
    }
}
