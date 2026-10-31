<?php

namespace App\Http\Controllers\Research;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ResearchDataset;
use App\RepositoryScanning\ReadSnapshotEvidence;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class EvidenceController extends Controller
{
    public function __invoke(Project $project, ResearchDataset $dataset, int $question, int $evidence, ReadSnapshotEvidence $reader): JsonResponse
    {
        Gate::authorize('view', $project);
        abort_unless((int) $dataset->project_id === (int) $project->id
            && (int) $dataset->revision->project_id === (int) $project->id, 404);
        $row = $dataset->questionRows()[$question] ?? null;
        $item = $row['evidence'][$evidence] ?? null;
        abort_unless(is_array($item), 404);
        try {
            $result = $reader->read($dataset->revision, $row['revision_fingerprint'], $item['path'], $item['start_line'], $item['end_line']);
        } catch (InvalidArgumentException) {
            return response()->json(['message' => __('ui.research.evidence_error')], 422, ['Cache-Control' => 'private, no-store']);
        }

        return response()->json($result, 200, ['Cache-Control' => 'private, no-store']);
    }
}
