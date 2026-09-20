<?php

namespace Fixture\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unsupported extends Model
{
    public function variable()
    {
        return $this->hasMany($this->related);
    }

    public function conditional()
    {
        if (config('enabled')) {
            return $this->hasMany(Track::class);
        }

        return $this->hasOne(User::class);
    }

    public function unpacked()
    {
        return $this->hasMany(...$this->arguments);
    }

    public function lateBound()
    {
        return $this->hasMany(static::class);
    }

    public function fluentUnknown()
    {
        return $this->hasMany(Track::class)->customScope();
    }

    public function missingThrough()
    {
        return $this->hasManyThrough(Track::class);
    }

    public function missingMorphName()
    {
        return $this->morphMany(Track::class);
    }

    public function nested()
    {
        return fn () => $this->hasMany(Track::class);
    }

    public function custom(): HasMany
    {
        return $this->customRelation();
    }
}

class Overridden extends Model
{
    public function hasMany($related, $foreignKey = null, $localKey = null)
    {
        return null;
    }

    public function tracks()
    {
        return $this->hasMany(Track::class);
    }
}

class Traited extends Model
{
    use UnknownTrait;

    public function tracks()
    {
        return $this->hasMany(Track::class);
    }
}

class NotEloquent
{
    public function tracks()
    {
        return $this->hasMany(Track::class);
    }
}

class UnknownBase extends MissingModel {}

class CycleA extends CycleB {}

class CycleB extends CycleA {}

if (true) {
    class ConditionalModel extends Model {}
}
