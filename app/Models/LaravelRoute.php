<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaravelRoute extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'method',
        'uri',
        'name',
        'action',
        'middleware',
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
    public function controllerSymbol(): BelongsTo
    {
        return $this->belongsTo(CodeSymbol::class, 'controller_symbol_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'middleware' => 'array',
            'metadata' => 'array',
        ];
    }
}
