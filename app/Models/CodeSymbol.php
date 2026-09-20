<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CodeSymbol extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'kind',
        'name',
        'qualified_name',
        'signature',
        'visibility',
        'start_line',
        'end_line',
        'start_column',
        'end_column',
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

    /** @return BelongsTo<CodeFile, $this> */
    public function codeFile(): BelongsTo
    {
        return $this->belongsTo(CodeFile::class);
    }

    /** @return BelongsTo<CodeSymbol, $this> */
    public function parentSymbol(): BelongsTo
    {
        return $this->belongsTo(CodeSymbol::class, 'parent_symbol_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }
}
