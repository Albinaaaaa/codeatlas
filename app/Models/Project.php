<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Project extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'name',
        'slug',
        'description',
    ];

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return HasOne<ProjectProfile, $this> */
    public function profile(): HasOne
    {
        return $this->hasOne(ProjectProfile::class);
    }

    /** @return HasOne<ProjectSetting, $this> */
    public function settings(): HasOne
    {
        return $this->hasOne(ProjectSetting::class);
    }

    /** @return HasMany<ProjectTechnology, $this> */
    public function technologies(): HasMany
    {
        return $this->hasMany(ProjectTechnology::class);
    }

    /** @return HasMany<ProjectSource, $this> */
    public function sources(): HasMany
    {
        return $this->hasMany(ProjectSource::class);
    }

    /** @return HasMany<ProjectRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(ProjectRevision::class);
    }

    /** @return HasMany<IndexRun, $this> */
    public function indexRuns(): HasMany
    {
        return $this->hasMany(IndexRun::class);
    }

    /** @return HasMany<Conversation, $this> */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
