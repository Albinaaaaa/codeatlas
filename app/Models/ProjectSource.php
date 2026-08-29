<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProjectSource extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'type',
        'name',
        'repository_url',
        'default_branch',
        'external_id',
        'metadata',
    ];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasOne<LocalProjectSource, $this> */
    public function local(): HasOne
    {
        return $this->hasOne(LocalProjectSource::class);
    }

    /** @return HasMany<ProjectRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(ProjectRevision::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
