<?php

namespace App\ProjectSources;

use App\Enums\ProjectSourceType;
use App\Models\LocalProjectSource;
use App\Models\Project;
use App\Models\ProjectSource;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class LocalDirectorySource
{
    private const SOURCE_NAME = 'Local directory';

    public function __construct(
        private readonly LocalSourceWorkspace $workspace,
    ) {}

    public function connect(Project $project, string $path): ProjectSource
    {
        $relativePath = $this->workspace->canonicalRelativeDirectory($path);

        if ($relativePath === null) {
            throw new InvalidArgumentException('The local source path must be an existing directory.');
        }

        return DB::transaction(function () use ($project, $relativePath): ProjectSource {
            $source = $this->query($project)
                ->lockForUpdate()
                ->first();

            if ($source === null) {
                $source = $project->sources()->create([
                    'type' => ProjectSourceType::LocalDirectory->value,
                    'name' => self::SOURCE_NAME,
                ]);
            }

            $localSource = $source->local()->first();

            if ($localSource === null) {
                $localSource = new LocalProjectSource(['path' => $relativePath]);
                $localSource->project()->associate($project);
                $source->local()->save($localSource);
            } else {
                $localSource->update(['path' => $relativePath]);
            }

            return $source->setRelation('local', $localSource);
        });
    }

    public function disconnect(Project $project): void
    {
        DB::transaction(function () use ($project): void {
            $this->query($project)
                ->lockForUpdate()
                ->first()
                ?->delete();
        });
    }

    public function findFor(Project $project): ?ProjectSource
    {
        return $this->query($project)
            ->with('local')
            ->first();
    }

    public function isAvailable(ProjectSource $source): bool
    {
        return $source->local !== null
            && $this->workspace->resolveDirectory(
                $source->local->path,
            ) !== null;
    }

    public function isEnabled(): bool
    {
        return $this->workspace->isEnabled();
    }

    public function isConfigured(): bool
    {
        return $this->workspace->isConfigured();
    }

    public function displayPath(ProjectSource $source): ?string
    {
        return $source->local === null
            ? null
            : $this->workspace->displayPath($source->local->path);
    }

    public function runtimePath(ProjectSource $source): ?string
    {
        return $source->local === null
            ? null
            : $this->workspace->resolveDirectory($source->local->path);
    }

    /** @return HasMany<ProjectSource, Project> */
    private function query(Project $project): HasMany
    {
        return $project->sources()
            ->where('type', ProjectSourceType::LocalDirectory->value)
            ->where('name', self::SOURCE_NAME);
    }
}
