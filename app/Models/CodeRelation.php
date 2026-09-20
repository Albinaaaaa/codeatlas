<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CodeRelation extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'type',
        'target_name',
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

    /** @return BelongsTo<CodeFile, $this> */
    public function codeFile(): BelongsTo
    {
        return $this->belongsTo(CodeFile::class);
    }

    /** @return BelongsTo<CodeSymbol, $this> */
    public function fromSymbol(): BelongsTo
    {
        return $this->belongsTo(CodeSymbol::class, 'from_symbol_id');
    }

    /** @return BelongsTo<CodeSymbol, $this> */
    public function toSymbol(): BelongsTo
    {
        return $this->belongsTo(CodeSymbol::class, 'to_symbol_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }
}
