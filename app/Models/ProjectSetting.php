<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectSetting extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'included_paths',
        'excluded_paths',
        'index_vendor',
        'index_tests',
        'max_file_size',
    ];

    protected $primaryKey = 'project_id';

    public $incrementing = false;

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'included_paths' => 'array',
            'excluded_paths' => 'array',
            'index_vendor' => 'boolean',
            'index_tests' => 'boolean',
            'max_file_size' => 'integer',
        ];
    }
}
