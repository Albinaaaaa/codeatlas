<?php

namespace Tests\Feature\Research;

use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\PrepareResearchExperimentJob;
use App\Jobs\RunResearchExperimentBatchJob;
use App\Models\ResearchDataset;
use App\Models\ResearchExperiment;
use App\Models\ThesisAnswerEvaluation;
use App\Models\ThesisExperimentAttempt;
use App\Models\User;
use App\Research\PrepareResearchExperiment;
use App\Research\RunExperiments;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\ResearchFixture;
use Tests\RefreshDatabase;
use Tests\TestCase;

class ResearchEvaluationUiTest extends TestCase
{
    use RefreshDatabase;
    use ResearchFixture;

    private string $exportRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeResearchStorage();
        Storage::fake('thesis_research');
        config(['inertia.ssr.enabled' => false, 'services.ollama.url' => 'http://ollama.test:11434',
            'thesis' => require base_path('config/thesis.php')]);
        $this->exportRoot = storage_path('app/private/test-research-ui-'.Str::uuid());
        config(['filesystems.disks.thesis_research.root' => $this->exportRoot]);
    }

    protected function tearDown(): void
    {
        if (isset($this->exportRoot) && str_starts_with($this->exportRoot, storage_path('app/private/test-research-ui-'))
            && is_dir($this->exportRoot)) {
            File::deleteDirectory($this->exportRoot);
        }
        parent::tearDown();
    }

    public function test_http_workflow_keeps_evaluation_blind_then_unlocks_results_and_private_exports(): void
    {
        $fixture = $this->researchFixture();
        $project = $fixture['revision']->project;
        $this->actingAs($project->owner);
        $datasetUrl = '/projects/'.$project->id.'/research/datasets';
        $jsonl = json_encode($fixture['row'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        $this->post($datasetUrl, ['revision_id' => $fixture['revision']->id,
            'upload' => UploadedFile::fake()->createWithContent('pilot.jsonl', $jsonl)])->assertSessionHasNoErrors();
        $dataset = ResearchDataset::query()->where('project_id', $project->id)->sole();
        $datasetUrl .= '/'.$dataset->id;
        $this->post($datasetUrl.'/questions/0/verify', ['lock_version' => 1, 'confirmed' => true])->assertSessionHasNoErrors();
        $this->post($datasetUrl.'/validate', ['lock_version' => 2])->assertSessionHasNoErrors();
        $this->post($datasetUrl.'/freeze', ['lock_version' => 3])->assertSessionHasNoErrors();

        Queue::fake();
        $base = '/projects/'.$project->id.'/research/experiments';
        $this->post($base, ['dataset_id' => $dataset->id, 'repetitions' => 1])->assertSessionHasNoErrors();
        $experiment = ResearchExperiment::query()->sole();
        (new PrepareResearchExperimentJob($experiment->id))->handle(app(PrepareResearchExperiment::class));
        $experiment->refresh();
        $this->assertSame('ready', $experiment->status);

        $this->fakeOllama();
        $this->post($base.'/'.$experiment->id.'/start')->assertSessionHasNoErrors();
        $experiment->refresh();
        $executionIdentity = (string) $experiment->getAttribute('execution_identity');
        $batchStates = [];
        foreach ([1, 2, 3] as $batch) {
            (new RunResearchExperimentBatchJob($experiment->id, $executionIdentity, $batch))
                ->handle(app(RunExperiments::class));
            $experiment->refresh();
            $batchStates[] = [$experiment->status, $experiment->batch_number, $experiment->pending_cases, $experiment->completed_cases];
        }
        $this->assertSame('completed', $experiment->status, json_encode($batchStates));
        $this->assertSame(9, ThesisExperimentAttempt::query()->count());
        $attemptsBefore = ThesisExperimentAttempt::query()->orderBy('id')->get()->map(fn ($attempt): array => $attempt->getRawOriginal())->all();

        $evaluationUrl = $base.'/'.$experiment->id.'/evaluation';
        $blindResponse = $this->inertiaGet($evaluationUrl)->assertOk();
        $blind = $blindResponse->json('props');
        $this->assertSame(9, $blind['progress']['eligible']);
        $this->assertCount(9, $blind['answers']);
        $serializedBlind = json_encode($blind, JSON_THROW_ON_ERROR);
        foreach ([...config('thesis.models'), ...config('thesis.methods')] as $hidden) {
            $this->assertStringNotContainsString($hidden, $serializedBlind);
        }
        $this->assertSame([], array_intersect(['model_provenance', 'attempt_id', 'case_key', 'prompt_sha256', 'actual_metrics', 'timings_ms'], $this->nestedKeys($blind)));
        foreach ($blind['answers'] as $answer) {
            $this->assertSame(['evaluation_id', 'rubric_version', 'question', 'reference_answer', 'required_facts', 'answer',
                'correctness', 'fact_coverage', 'notes'], array_keys($answer));
        }

        $resultsUrl = $base.'/'.$experiment->id.'/results';
        $locked = $this->inertiaGet($resultsUrl)->assertOk()->json('props');
        $this->assertTrue($locked['locked']);
        $this->assertNull($locked['summary']);
        $serializedLocked = json_encode($locked, JSON_THROW_ON_ERROR);
        foreach ([...config('thesis.models'), ...config('thesis.methods')] as $hidden) {
            $this->assertStringNotContainsString($hidden, $serializedLocked);
        }
        $this->post($base.'/'.$experiment->id.'/exports')->assertSessionHasErrors('results');

        $first = $blind['answers'][0];
        $grade = ['evaluation_id' => $first['evaluation_id'], 'correctness' => 2,
            'fact_coverage' => array_fill(0, count($first['required_facts']), true), 'notes' => 'Verified against reference.'];
        $this->post($evaluationUrl, $grade)->assertSessionHasNoErrors();
        $this->post($evaluationUrl, $grade)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('thesis_answer_evaluations', 1);
        $this->assertSame('user:'.$project->user_id, ThesisAnswerEvaluation::query()->firstOrFail()->details()['reviewer']);
        $this->post($evaluationUrl, [...$grade, 'correctness' => 0])->assertSessionHasErrors('evaluation_id');
        $this->assertSame($attemptsBefore, ThesisExperimentAttempt::query()->orderBy('id')->get()->map(fn ($attempt): array => $attempt->getRawOriginal())->all());

        foreach (array_slice($blind['answers'], 1) as $answer) {
            $this->post($evaluationUrl, ['evaluation_id' => $answer['evaluation_id'], 'correctness' => 2,
                'fact_coverage' => array_fill(0, count($answer['required_facts']), true), 'notes' => ''])->assertSessionHasNoErrors();
        }
        $this->assertDatabaseCount('thesis_answer_evaluations', 9);
        $results = $this->inertiaGet($resultsUrl)->assertOk()->json('props');
        $this->assertFalse($results['locked']);
        $this->assertCount(9, $results['summary']['groups']);
        $this->assertSame(1, $results['summary']['groups'][0]['metrics']['correctness']['n']);
        $this->assertArrayNotHasKey('canonical_attempt_ids', $results['summary']);
        $this->assertNotNull($experiment->fresh()->getAttribute('results_unlocked_at'));
        $this->post($base.'/'.$experiment->id.'/resume')->assertSessionHasErrors('experiment');

        $this->post($base.'/'.$experiment->id.'/exports')->assertRedirect();
        $page = $this->get($resultsUrl)->assertOk()->assertInertia(fn (Assert $assert) => $assert
            ->component('projects/research/experiments/results')->where('locked', false));
        $bundle = $experiment->fresh()->getAttribute('results_unlocked_at') !== null
            ? $this->latestBundleId((string) $experiment->experiment_identity) : '';
        foreach (['raw.json', 'raw.jsonl', 'attempts.csv', 'summary.json', 'summary.csv'] as $file) {
            $this->get($base.'/'.$experiment->id.'/exports/'.$bundle.'/'.$file)->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private');
        }
        $this->assertStringNotContainsString($this->exportRoot, $page->getContent());
    }

    public function test_foreign_project_cannot_view_or_submit_evaluations_and_invalid_fact_decisions_are_rejected(): void
    {
        $fixture = $this->researchFixture();
        $project = $fixture['revision']->project;
        $other = User::factory()->create();
        $otherProject = $other->projects()->create(['name' => 'Other', 'slug' => 'other']);
        $this->actingAs($project->owner);
        $dataset = (new ResearchDataset)->forceFill(['project_id' => $project->id, 'project_revision_id' => $fixture['revision']->id,
            'dataset_id' => 'frozen', 'dataset_version' => '0.1.0', 'split' => 'pilot', 'status' => 'frozen',
            'frozen_path' => 'datasets/foreign-test.jsonl', 'frozen_sha256' => str_repeat('a', 64), 'questions' => []]);
        $dataset->save();
        $experiment = (new ResearchExperiment)->forceFill(['project_id' => $project->id, 'research_dataset_id' => $dataset->id,
            'owner_id' => $project->user_id, 'dataset_sha256' => str_repeat('a', 64), 'revision_fingerprints' => [],
            'repetitions' => 1, 'status' => 'completed']);
        $experiment->save();
        $this->actingAs($other)->get('/projects/'.$project->id.'/research/experiments/'.$experiment->id.'/evaluation')->assertForbidden();
        $secondProject = $project->owner->projects()->create(['name' => 'Second project', 'slug' => 'second-project']);
        $this->actingAs($project->owner)->get('/projects/'.$secondProject->id.'/research/experiments/'.$experiment->id.'/evaluation')->assertNotFound();
        $this->actingAs($project->owner)->post('/projects/'.$project->id.'/research/experiments/'.$experiment->id.'/evaluation', [
            'evaluation_id' => str_repeat('a', 64), 'correctness' => 2, 'fact_coverage' => ['yes'],
        ])->assertSessionHasErrors('fact_coverage.0');
    }

    private function inertiaGet(string $url): TestResponse
    {
        $version = app(HandleInertiaRequests::class)->version(request());

        return $this->get($url, ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'X-Inertia-Version' => $version]);
    }

    /** @param array<string, mixed> $value
     * @return list<string>
     */
    private function nestedKeys(array $value): array
    {
        $keys = [];
        foreach ($value as $key => $item) {
            $keys[] = (string) $key;
            if (is_array($item)) {
                $keys = [...$keys, ...$this->nestedKeys($item)];
            }
        }

        return $keys;
    }

    private function latestBundleId(string $experiment): string
    {
        $root = $this->exportRoot.'/results/'.$experiment;
        $folders = glob($root.'/*', GLOB_ONLYDIR) ?: [];
        sort($folders);

        return basename((string) end($folders));
    }

    private function fakeOllama(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/api/version')) {
                return Http::response(['version' => 'synthetic-test-runtime']);
            }
            if (str_ends_with($request->url(), '/api/tags')) {
                return Http::response(['models' => array_map(fn (string $model): array => [
                    'name' => $model, 'digest' => str_repeat('a', 64),
                ], config('thesis.models'))]);
            }
            if (str_ends_with($request->url(), '/api/show')) {
                return Http::response(['model_info' => ['qwen2.context_length' => 32768],
                    'capabilities' => ['completion'], 'template' => '{{ .Prompt }}']);
            }

            return Http::response(['model' => $request['model'], 'response' => 'The answer is 42.', 'done' => true,
                'done_reason' => 'stop', 'prompt_eval_count' => 100, 'eval_count' => 12,
                'total_duration' => 1_000_000_000, 'load_duration' => 100_000_000,
                'prompt_eval_duration' => 200_000_000, 'eval_duration' => 700_000_000]);
        });
    }
}
