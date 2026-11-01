<?php

namespace App\AskCodeAtlas;

use Illuminate\Support\Facades\DB;

final class RecoverStaleQuestions
{
    public function handle(int $userId, int $projectId): void
    {
        $staleAfter = max(120, (int) config('thesis.inference.timeout_seconds', 300) * 3 + 120);
        $cutoff = now()->subSeconds($staleAfter);

        DB::table('repository_questions')
            ->where('user_id', $userId)
            ->where('project_id', $projectId)
            ->whereIn('status', ['preparing', 'running'])
            ->where('heartbeat_at', '<', $cutoff)
            ->update([
                'status' => 'incomplete',
                'safe_error' => 'Processing stopped before a result was saved. Submit the question again to retry.',
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('repository_questions')
            ->where('user_id', $userId)
            ->where('project_id', $projectId)
            ->where('status', 'queued')
            ->where('queued_at', '<', $cutoff)
            ->update([
                'status' => 'incomplete',
                'safe_error' => 'No worker claimed this request. Check the ask queue and submit again.',
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
