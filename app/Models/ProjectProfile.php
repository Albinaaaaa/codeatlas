<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectProfile extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'summary',
        'architecture',
        'primary_language',
        'framework',
        'framework_version',
        'metadata',
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
        return ['metadata' => 'array'];
    }
}
