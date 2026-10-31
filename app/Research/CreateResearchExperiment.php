<?php

namespace App\Research;

use App\Models\Project;
use App\Models\ResearchDataset;
use App\Models\ResearchExperiment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class CreateResearchExperiment
{
    public function __construct(private readonly DatasetValidator $validator) {}

    public function handle(Project $project, ResearchDataset $dataset, User $owner, int $repetitions): ResearchExperiment
    {
        if ($dataset->project_id !== $project->id || $dataset->status !== 'frozen' || ! $dataset->frozen_path || ! $dataset->frozen_sha256) {
            throw ValidationException::withMessages(['dataset_id' => __('ui.research.experiment_dataset_invalid')]);
        }
        $disk = Storage::disk('thesis_research');
        if (! $disk->exists($dataset->frozen_path)) {
            throw ValidationException::withMessages(['dataset_id' => __('ui.research.experiment_dataset_invalid')]);
        }
        $raw = $disk->get($dataset->frozen_path);
        if (! hash_equals($dataset->frozen_sha256, hash('sha256', $raw))) {
            throw ValidationException::withMessages(['dataset_id' => __('ui.research.experiment_dataset_invalid')]);
        }
        $report = $this->validator->validate($disk->path($dataset->frozen_path));
        if ($report['errors'] !== [] || ! hash_equals($dataset->frozen_sha256, $report['dataset_sha256'])) {
            throw ValidationException::withMessages(['dataset_id' => __('ui.research.experiment_dataset_invalid')]);
        }
        $fingerprints = [];
        foreach ($report['questions'] as $question) {
            $fingerprints[(string) $question->revisionId] = $question->fingerprint;
        }

        return DB::transaction(function () use ($project, $dataset, $owner, $fingerprints, $repetitions): ResearchExperiment {
            $experiment = new ResearchExperiment;
            $experiment->forceFill([
                'project_id' => $project->id, 'research_dataset_id' => $dataset->id, 'owner_id' => $owner->id,
                'dataset_sha256' => $dataset->frozen_sha256, 'revision_fingerprints' => $fingerprints,
                'repetitions' => $repetitions, 'status' => 'preparing',
            ])->save();

            return $experiment;
        });
    }
}
