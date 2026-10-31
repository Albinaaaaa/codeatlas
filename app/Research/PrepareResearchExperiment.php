<?php

namespace App\Research;

use App\CodeChunking\BuildRevisionChunks;
use App\Models\ProjectRevision;
use App\Models\ResearchDataset;
use App\Models\ResearchExperiment;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class PrepareResearchExperiment
{
    public function __construct(private readonly DatasetValidator $validator, private readonly BuildRevisionChunks $chunks,
        private readonly RunExperiments $runner) {}

    public function handle(ResearchExperiment $experiment): void
    {
        $experiment->refresh();
        if ($experiment->status !== 'preparing') {
            return;
        }
        $dataset = ResearchDataset::query()->findOrFail($experiment->research_dataset_id);
        if ((int) $dataset->project_id !== (int) $experiment->project_id || $dataset->status !== 'frozen'
            || ! is_string($dataset->frozen_path) || ! is_string($dataset->frozen_sha256)) {
            throw new RuntimeException('Frozen dataset is unavailable.');
        }
        $disk = Storage::disk('thesis_research');
        if (! $disk->exists($dataset->frozen_path)) {
            throw new RuntimeException('Frozen dataset file is unavailable.');
        }
        $raw = $disk->get($dataset->frozen_path);
        if (! hash_equals($experiment->dataset_sha256, hash('sha256', $raw))) {
            throw new RuntimeException('Frozen dataset checksum changed.');
        }
        $path = $disk->path($dataset->frozen_path);
        $report = $this->validator->validate($path);
        if ($report['errors'] !== [] || ! hash_equals($experiment->dataset_sha256, $report['dataset_sha256'])) {
            throw new RuntimeException('Frozen dataset validation failed.');
        }
        $revisionIds = [];
        $fingerprints = $experiment->getAttribute('revision_fingerprints');
        if (! is_array($fingerprints)) {
            throw new RuntimeException('Experiment revision identities are unavailable.');
        }
        foreach ($report['questions'] as $question) {
            $fingerprint = $fingerprints[(string) $question->revisionId] ?? null;
            if (! is_string($fingerprint) || ! hash_equals($fingerprint, $question->fingerprint)) {
                throw new RuntimeException('Dataset revision fingerprint changed.');
            }
            $revisionIds[$question->revisionId] = true;
        }
        if (ProjectRevision::query()->where('project_id', $experiment->project_id)
            ->whereIn('id', array_keys($revisionIds))->count() !== count($revisionIds)) {
            throw new RuntimeException('Dataset contains a revision outside its project.');
        }
        $experiment->forceFill(['preparation_total' => count($revisionIds), 'preparation_completed' => 0])->save();
        foreach (array_keys($revisionIds) as $revisionId) {
            $this->chunks->handle((int) $revisionId);
            $experiment->increment('preparation_completed');
            $experiment->forceFill(['heartbeat_at' => now()])->save();
        }
        $preview = $this->runner->run($path, $experiment->repetitions, dryRun: true);
        if ($preview['total'] !== count($report['questions']) * 9 * $experiment->repetitions
            || $preview['dataset_sha256'] !== $experiment->dataset_sha256) {
            throw new RuntimeException('Prepared experiment matrix is inconsistent.');
        }
        $configurationIdentity = hash('sha256', json_encode(config('thesis'), JSON_THROW_ON_ERROR));
        $experiment->forceFill(['experiment_identity' => $preview['experiment_id'], 'configuration_identity' => $configurationIdentity,
            'total_cases' => $preview['total'], 'pending_cases' => $preview['total'], 'status' => 'ready',
            'safe_error' => null, 'heartbeat_at' => now()])->save();
    }
}
