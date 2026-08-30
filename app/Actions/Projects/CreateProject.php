<?php

namespace App\Actions\Projects;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Str;

class CreateProject
{
    /**
     * @param  array{name: string, description: string|null}  $attributes
     */
    public function handle(User $owner, array $attributes): Project
    {
        $baseSlug = Str::substr(Str::slug($attributes['name']) ?: 'project', 0, 240);
        $slug = $baseSlug;
        $suffix = 2;

        while ($owner->projects()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        return $owner->projects()->create([
            'name' => $attributes['name'],
            'slug' => $slug,
            'description' => $attributes['description'],
        ]);
    }
}
