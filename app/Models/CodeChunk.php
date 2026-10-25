<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CodeChunk extends Model
{
    /** @var list<string> */
    protected $fillable = ['chunk_index', 'content', 'start_line', 'end_line', 'metadata'];

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
    public function codeSymbol(): BelongsTo
    {
        return $this->belongsTo(CodeSymbol::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['metadata' => 'array', 'chunk_index' => 'integer', 'start_line' => 'integer', 'end_line' => 'integer'];
    }
}
