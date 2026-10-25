<?php

namespace Tests\Feature\Research;

use App\CodeChunking\BuildRevisionChunks;
use App\Inference\OllamaInference;
use App\Inference\OllamaInferenceException;
use App\Models\CodeChunk;
use App\PhpAnalysis\PhpRevisionAnalyzer;
use App\Research\DatasetQuestion;
use App\Research\DatasetValidator;
use App\Research\RecordExperiment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Concerns\ResearchFixture;
use Tests\RefreshDatabase;
use Tests\TestCase;

class ExperimentRecordTest extends TestCase
{
    use RefreshDatabase, ResearchFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeResearchStorage();
        config(['thesis' => require base_path('config/thesis.php'), 'services.ollama.url' => 'http://ollama.test:11434']);
    }

    public function test_reference_data_never_enters_inference_and_records_are_encrypted(): void
    {
        $fixture = $this->researchFixture();
        $row = $fixture['row'];
        $row['reference_answer'] = 'The function returns 42. PRIVATE_REFERENCE_SENTINEL';
        $row['required_facts'] = ['Return value is 42. PRIVATE_RUBRIC_SENTINEL'];
        $row['verification']['notes'] = 'PRIVATE_VERIFICATION_SENTINEL';
        $question = $this->question($this->datasetFile([$row]));
        $this->fakeInference();
        $result = app(OllamaInference::class)->ask(...$question->inferenceArguments('keyword', 'qwen2.5-coder:1.5b'));
        Http::assertSent(function (Request $request): bool {
            if (! str_ends_with($request->url(), '/api/generate')) {
                return false;
            }
            $body = json_encode($request->data(), JSON_THROW_ON_ERROR);
            foreach (['PRIVATE_REFERENCE_SENTINEL', 'PRIVATE_RUBRIC_SENTINEL', 'PRIVATE_VERIFICATION_SENTINEL', 'gold-location-only.txt', 'required_facts', 'reference_answer'] as $private) {
                $this->assertStringNotContainsString($private, $body);
            }

            return true;
        });
        $record = app(RecordExperiment::class)->record($question, $result, (string) Str::uuid(), 1,
            ['ollama_version' => '0.33.1', 'model_digest' => str_repeat('a', 64)]);
        $fresh = $record->fresh();
        $details = $fresh->details();
        $this->assertSame('successful', $fresh->status);
        $this->assertSame($result['prompt'], $details['inference']['prompt']);
        $this->assertSame($result['prompt_hash'], $fresh->prompt_sha256);
        $this->assertSame($result['selected_context'], $details['inference']['selected_context']);
        $this->assertSame($result['selected_chunks'], $details['inference']['selected_chunks']);
        $this->assertSame($result['actual_metrics'], $details['inference']['actual_metrics']);
        $this->assertEquals($result['timings_ms'], $details['inference']['timings_ms']);
        $this->assertSame($result['estimated_token_usage']['prompt_tokens'], $details['inference']['estimated_token_usage']['prompt_tokens']);
        $this->assertSame($row, $details['dataset_question']);
        $this->assertSame('0.33.1', $details['model_provenance']['ollama_version']);
        $this->assertSame($question->datasetSha256, $fresh->dataset_sha256);
        $this->assertSame($question->fingerprint, $fresh->revision_fingerprint);
        $raw = DB::table('thesis_experiment_attempts')->where('id', $record->id)->value('payload');
        $this->assertStringNotContainsString('PRIVATE_REFERENCE_SENTINEL', $raw);
        $this->assertStringNotContainsString('return 42', $raw);
        $this->assertArrayNotHasKey('payload', $fresh->toArray());
    }

    public function test_failed_inspection_timeout_and_partial_responses_can_be_saved(): void
    {
        $fixture = $this->researchFixture();
        $question = $this->question($fixture['path']);
        $keys = [];
        foreach (['inspection', 'timeout', 'partial', 'truncated', 'unknown'] as $case) {
            $this->fakeInference($case);
            try {
                $outcome = app(OllamaInference::class)->ask(...$question->inferenceArguments('keyword', 'qwen2.5-coder:1.5b'));
            } catch (OllamaInferenceException $exception) {
                $this->assertStringNotContainsString('private upstream body', $exception->getMessage());
                $this->assertStringNotContainsString('return 42', json_encode($exception, JSON_THROW_ON_ERROR));
                $outcome = $exception;
            }
            $record = app(RecordExperiment::class)->record($question, $outcome, (string) Str::uuid(), 1);
            $keys[] = $record->run_key;
            $inference = $record->fresh()->details()['inference'];
            $this->assertSame(in_array($case, ['inspection', 'timeout'], true) ? 'failed' : 'incomplete', $record->status);
            $this->assertSame(hash('sha256', $inference['prompt']), $record->prompt_sha256);
            $this->assertNotEmpty($inference['selected_context']);
            $this->assertSame($case !== 'inspection', $inference['submission_attempted']);
            if (in_array($case, ['inspection', 'timeout'], true)) {
                $this->assertNull($inference['answer']);
                $this->assertSame([null], array_values(array_unique($inference['actual_metrics'])));
                $this->assertNotEmpty($inference['error']['message']);
            } else {
                $this->assertSame('42', $inference['answer']);
                $this->assertSame(12, $inference['actual_metrics']['eval_count']);
            }
        }
        $this->assertCount(1, array_unique($keys));
        $this->assertDatabaseCount('thesis_experiment_attempts', 5);
    }

    public function test_stable_run_identity_separate_attempts_and_frozen_configuration(): void
    {
        $fixture = $this->researchFixture();
        $question = $this->question($fixture['path']);
        $this->fakeInference();
        $result = app(OllamaInference::class)->ask(...$question->inferenceArguments('keyword', 'qwen2.5-coder:1.5b'));
        $recorder = app(RecordExperiment::class);
        $a = $recorder->record($question, $result, (string) Str::uuid(), 1);
        config(['thesis.generation.seed' => 999]);
        $b = $recorder->record($question, $result, (string) Str::uuid(), 1);
        $wireResult = json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $c = $recorder->record($question, $wireResult, (string) Str::uuid(), 2);
        $this->assertSame($a->run_key, $b->run_key);
        $this->assertSame($a->run_key, $c->run_key);
        $this->assertNotSame($a->attempt_id, $b->attempt_id);
        $this->assertSame(2, $c->repetition_index);
        $this->assertSame(42, $c->details()['inference']['protocol_configuration']['generation']['seed']);
        $this->assertDatabaseCount('thesis_experiment_attempts', 3);
        $changed = app(OllamaInference::class)->ask(...$question->inferenceArguments('keyword', 'qwen2.5-coder:1.5b'));
        $d = $recorder->record($question, $changed, (string) Str::uuid(), 1);
        $this->assertNotSame($a->run_key, $d->run_key);
        try {
            $recorder->record($question, $result, $a->attempt_id, 1);
            $this->fail('Expected duplicate attempt rejection.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('cannot be overwritten', $exception->getMessage());
        }
        $this->assertDatabaseCount('thesis_experiment_attempts', 4);
        $this->assertSame($result['prompt'], $a->fresh()->details()['inference']['prompt']);
    }

    public function test_records_survive_reanalysis_and_rechunking_without_symbol_or_chunk_foreign_keys(): void
    {
        $fixture = $this->researchFixture();
        $question = $this->question($fixture['path']);
        $this->fakeInference();
        $result = app(OllamaInference::class)->ask(...$question->inferenceArguments('keyword', 'qwen2.5-coder:1.5b'));
        $record = app(RecordExperiment::class)->record($question, $result, (string) Str::uuid(), 1);
        $ids = CodeChunk::query()->where('project_revision_id', $question->revisionId)->pluck('id')->all();
        CodeChunk::query()->where('project_revision_id', $question->revisionId)->delete();
        app(PhpRevisionAnalyzer::class)->analyze($fixture['revision'], $fixture['root']);
        app(BuildRevisionChunks::class)->handle($question->revisionId);
        $this->assertNotSame($ids, CodeChunk::query()->where('project_revision_id', $question->revisionId)->pluck('id')->all());
        $this->assertSame($result['prompt'], $record->fresh()->details()['inference']['prompt']);
        $this->assertSame($result['selected_context'], $record->fresh()->details()['inference']['selected_context']);
        $this->assertSame($result['selected_chunks'], $record->fresh()->details()['inference']['selected_chunks']);
    }

    public function test_revision_isolation_and_corrupt_provenance_cannot_be_saved(): void
    {
        $fixture = $this->researchFixture();
        $other = $this->researchFixture($fixture['revision']->project);
        $question = $this->question($fixture['path']);
        $this->fakeInference();
        $result = app(OllamaInference::class)->ask(...$question->inferenceArguments('keyword', 'qwen2.5-coder:1.5b'));
        $recorder = app(RecordExperiment::class);
        foreach (['revision', 'hash', 'source', 'attempt', 'repetition'] as $case) {
            $corrupt = $result;
            if ($case === 'hash') {
                $corrupt['prompt_hash'] = str_repeat('0', 64);
            }
            if ($case === 'source') {
                $corrupt['selected_context'][0]['content'] = 'tampered';
            }
            try {
                $recorder->record($case === 'revision' ? $this->question($other['path']) : $question, $corrupt,
                    $case === 'attempt' ? 'bad' : (string) Str::uuid(), $case === 'repetition' ? 0 : 1);
                $this->fail('Expected an invalid experiment snapshot.');
            } catch (InvalidArgumentException) {
                $this->assertDatabaseCount('thesis_experiment_attempts', 0);
            }
        }
    }

    private function question(string $path): DatasetQuestion
    {
        $report = app(DatasetValidator::class)->validate($path);
        $this->assertSame([], $report['errors']);

        return $report['questions'][0];
    }

    private function fakeInference(string $case = 'success'): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $response = ['model' => 'qwen2.5-coder:1.5b', 'response' => '42', 'done' => $case !== 'partial',
            'done_reason' => $case === 'truncated' ? 'length' : 'stop', 'prompt_eval_count' => 100, 'eval_count' => 12,
            'total_duration' => 1_000_000_000, 'load_duration' => 100_000_000, 'prompt_eval_duration' => 200_000_000, 'eval_duration' => 700_000_000];
        if ($case === 'unknown') {
            unset($response['done_reason']);
        }
        Http::fake([
            '*/api/show' => Http::response($case === 'inspection' ? ['error' => 'private upstream body'] : [
                'model_info' => ['qwen2.context_length' => 32768], 'capabilities' => ['completion'], 'template' => '{{ .Prompt }}'], $case === 'inspection' ? 404 : 200),
            '*/api/generate' => $case === 'timeout' ? fn () => throw new ConnectionException('private upstream body timeout') : Http::response($response),
        ]);
    }
}
