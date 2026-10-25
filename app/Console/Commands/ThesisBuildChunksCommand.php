<?php

namespace App\Console\Commands;

use App\CodeChunking\BuildRevisionChunks;
use Illuminate\Console\Command;
use RuntimeException;

class ThesisBuildChunksCommand extends Command
{
    protected $signature = 'thesis:build-chunks {revisionId}';

    protected $description = 'Build deterministic PHP chunks from an indexed private revision snapshot';

    public function handle(BuildRevisionChunks $builder): int
    {
        $id = filter_var($this->argument('revisionId'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            $this->error('revisionId must be a positive integer.');

            return self::FAILURE;
        }
        try {
            $result = $builder->handle($id);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info("Processed {$result['files']} PHP files; {$result['chunks']} chunks ({$result['created']} created, {$result['updated']} updated).");

        return self::SUCCESS;
    }
}
