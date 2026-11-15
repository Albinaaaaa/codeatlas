<?php

namespace App\Actions\Projects;

use App\Models\ProjectRevision;
use Illuminate\Support\Facades\DB;

final class GenerateProjectProfile
{
    public function handle(ProjectRevision $revision): void
    {
        $projectId = (int) $revision->project_id;
        $revisionId = (int) $revision->id;
        $scope = static fn ($query) => $query
            ->where('project_id', $projectId)
            ->where('project_revision_id', $revisionId);

        $phpFiles = DB::table('code_files')->where($scope)->where('language', 'PHP')->count();
        $files = DB::table('code_files')->where($scope)->count();
        $routes = DB::table('laravel_routes')->where($scope)->count();
        $models = DB::table('laravel_models')->where($scope)->count();
        $tables = DB::table('database_tables')->where($scope)->count();

        $hasLaravelFacts = $routes > 0 || $models > 0 || $tables > 0
            || DB::table('code_files')->where($scope)->where('path', 'artisan')->exists();

        $technologies = [];
        if ($phpFiles > 0) {
            $technologies[] = ['name' => 'PHP', 'category' => 'language', 'confidence' => 1];
        }
        if ($hasLaravelFacts) {
            $technologies[] = ['name' => 'Laravel', 'category' => 'framework', 'confidence' => 0.9];
        }
        if ($tables > 0) {
            $technologies[] = ['name' => 'SQL', 'category' => 'database', 'confidence' => 0.8];
        }

        DB::transaction(function () use ($revision, $revisionId, $files, $phpFiles, $routes, $models, $tables, $hasLaravelFacts, $technologies): void {
            $project = $revision->project()->lockForUpdate()->firstOrFail();
            $profileMetadata = $project->profile?->getAttribute('metadata');
            if (is_array($profileMetadata) && (int) ($profileMetadata['project_revision_id'] ?? 0) > $revisionId) {
                return;
            }

            $project->profile()->updateOrCreate([], [
                'summary' => sprintf('Deterministic profile from revision %s: %d files, %d PHP files, %d routes, %d models and %d database tables.', $revision->identifier, $files, $phpFiles, $routes, $models, $tables),
                'architecture' => $hasLaravelFacts ? 'Laravel application' : ($phpFiles > 0 ? 'PHP application' : null),
                'primary_language' => $phpFiles > 0 ? 'PHP' : null,
                'framework' => $hasLaravelFacts ? 'Laravel' : null,
                'framework_version' => null,
                'metadata' => ['project_revision_id' => $revisionId, 'generated_by' => 'deterministic_indexed_facts'],
            ]);

            $project->technologies()->whereNotNull('metadata->project_revision_id')->delete();
            foreach ($technologies as $technology) {
                $project->technologies()->updateOrCreate(
                    ['category' => $technology['category'], 'name' => $technology['name']],
                    ['version' => null, 'confidence' => $technology['confidence'], 'metadata' => ['project_revision_id' => $revisionId]],
                );
            }
        });
    }
}
