<?php

namespace Fixture\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

abstract class BaseRecord extends Eloquent
{
    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    public function inherited()
    {
        return $this->hasMany(Track::class, 'owner_uuid', 'uuid');
    }
}
