<?php

namespace Tests\Unit\Jobs;

use App\Jobs\IndexProjectRevisionStageJob;
use PHPUnit\Framework\TestCase;

class IndexProjectRevisionStageJobTest extends TestCase
{
    public function test_job_carries_only_stable_revision_run_and_stage_identifiers(): void
    {
        $job = new IndexProjectRevisionStageJob(12, 34, 'database_analysis');

        self::assertSame(12, $job->revisionId);
        self::assertSame(34, $job->indexRunId);
        self::assertSame('database_analysis', $job->stage);
        self::assertSame('12:34:database_analysis', $job->uniqueId());
    }
}
