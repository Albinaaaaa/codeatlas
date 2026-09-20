<?php

namespace Fixture\Models;

use Fixture\Models\Track as Song;
use Fixture\State;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;

throw new \RuntimeException('Target application must never execute.');
class Album extends BaseRecord
{
    protected $table = 'music_albums';

    protected $connection = 'archive';

    protected $fillable = ['title', 'released_at'];

    protected $casts = ['title' => 'string', 'active' => 'integer'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'released_at' => 'datetime', 'state' => State::class];
    }

    public function track()
    {
        return $this->hasOne(Song::class);
    }

    public function tracks()
    {
        return $this->hasMany(Song::class, 'album_uuid', 'uuid');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, ownerKey: 'uuid', foreignKey: 'owner_uuid');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'album_user', 'album_uuid', 'user_uuid', 'uuid', 'uuid');
    }

    public function firstUser()
    {
        return $this->hasOneThrough(User::class, Song::class, 'album_uuid', 'track_uuid', 'uuid', 'uuid');
    }

    public function allUsers()
    {
        return $this->hasManyThrough(User::class, Song::class);
    }

    public function cover()
    {
        return $this->morphOne(Song::class, 'imageable', 'imageable_type', 'imageable_uuid', 'uuid');
    }

    public function images()
    {
        return $this->morphMany(Song::class, 'imageable');
    }

    public function subject()
    {
        return $this->morphTo(ownerKey: 'uuid');
    }

    public function tags()
    {
        return $this->morphToMany(Song::class, 'taggable', 'taggables', 'taggable_uuid', 'tag_uuid');
    }

    public function taggedBy()
    {
        return $this->morphedByMany(User::class, 'taggable');
    }

    public function parentAlbum()
    {
        return $this->belongsTo(self::class);
    }

    public function external()
    {
        return $this->hasMany('External\\Package\\Record');
    }
}

class Track extends Model {}

class User extends Authenticatable {}

class ChildAlbum extends Album
{
    protected $table = 'child_albums';

    public function tracks()
    {
        return $this->hasOne(Song::class);
    }
}

class NewsItem extends Model {}

class DynamicTable extends Model
{
    protected $table = 'not_the_runtime_table';

    public function getTable()
    {
        return config('table');
    }
}
