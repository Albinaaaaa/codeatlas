<?php

namespace Tests\Feature\Research;

use App\Jobs\PrepareResearchExperimentJob;
use App\Jobs\RunResearchExperimentBatchJob;
use App\Models\CodeChunk;
use App\Models\Project;
use App\Models\ResearchDataset;
use App\Models\ResearchExperiment;
use App\Models\ThesisExperimentAttempt;
use App\Models\User;
use App\Research\PrepareResearchExperiment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\ResearchFixture;
use Tests\RefreshDatabase;
use Tests\TestCase;

class ExperimentUiTest extends TestCase
{
    use RefreshDatabase;
    use ResearchFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeResearchStorage();
        Storage::fake('thesis_research');
        config(['inertia.ssr.enabled' => false]);
    }

    /** @return array{project: Project, dataset: ResearchDataset} */
    private function frozenDataset(int $questions = 1): array
    {
        $fixture = $this->researchFixture();
        $project = $fixture['revision']->project;
        $this->actingAs($project->owner);
        $rows = [];
        for ($index = 1; $index <= $questions; $index++) {
            $row = $fixture['row'];
            $row['question_id'] = 'q'.$index;
            $rows[] = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $base = '/projects/'.$project->id.'/research/datasets';
        $this->post($base, ['revision_id' => $fixture['revision']->id,
            'upload' => UploadedFile::fake()->createWithContent('pilot.jsonl', implode("\n", $rows)."\n")])->assertSessionHasNoErrors();
        $dataset = ResearchDataset::query()->where('project_id', $project->id)->sole();
        foreach (range(0, $questions - 1) as $index) {
            $this->post($base.'/'.$dataset->id.'/questions/'.$index.'/verify', ['lock_version' => $dataset->fresh()->lock_version, 'confirmed' => true])->assertSessionHasNoErrors();
        }
        $this->post($base.'/'.$dataset->id.'/validate', ['lock_version' => $dataset->fresh()->lock_version])->assertSessionHasNoErrors();
        $this->post($base.'/'.$dataset->id.'/freeze', ['lock_version' => $dataset->fresh()->lock_version])->assertSessionHasNoErrors();

        return ['project' => $project, 'dataset' => $dataset->fresh()];
    }

    public function test_owner_can_prepare_durable_experiment_and_preview_fixed_matrix_without_attempts(): void
    {
        ['project' => $project, 'dataset' => $dataset] = $this->frozenDataset(4);
        Queue::fake();
        $base = '/projects/'.$project->id.'/research/experiments';
        $this->get($base)->assertOk()->assertInertia(fn (Assert $page) => $page->component('projects/research/experiments/index')
            ->where('configuration.models.0', 'qwen2.5-coder:1.5b')->where('configuration.methods.2', 'structure_aware'));
        $this->post($base, ['dataset_id' => $dataset->id, 'repetitions' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $experiment = ResearchExperiment::query()->sole();
        $this->assertSame('preparing', $experiment->status);
        Queue::assertPushed(PrepareResearchExperimentJob::class);
        CodeChunk::query()->delete();
        (new PrepareResearchExperimentJob($experiment->id))->handle(app(PrepareResearchExperiment::class));
        $experiment->refresh();
        $this->assertSame('ready', $experiment->status);
        $this->assertSame(36, $experiment->total_cases);
        $this->assertGreaterThan(0, CodeChunk::query()->count());
        $this->assertSame(0, ThesisExperimentAttempt::query()->count());
        $this->get($base.'/'.$experiment->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('projects/research/experiments/show')->where('experiment.total_cases', 36));
    }

    public function test_foreign_datasets_and_foreign_experiments_are_rejected(): void
    {
        ['project' => $project, 'dataset' => $dataset] = $this->frozenDataset();
        $other = User::factory()->create();
        $otherProject = $other->projects()->create(['name' => 'Other', 'slug' => 'other']);
        $this->actingAs($other)->post('/projects/'.$otherProject->id.'/research/experiments', ['dataset_id' => $dataset->id, 'repetitions' => 1])->assertNotFound();
        $this->actingAs($project->owner)->post('/projects/'.$project->id.'/research/experiments', ['dataset_id' => $dataset->id, 'repetitions' => 11])->assertSessionHasErrors('repetitions');
    }

    public function test_start_and_resume_are_atomic_and_enqueue_only_bounded_background_work(): void
    {
        ['project' => $project, 'dataset' => $dataset] = $this->frozenDataset();
        Queue::fake();
        $base = '/projects/'.$project->id.'/research/experiments';
        $this->post($base, ['dataset_id' => $dataset->id, 'repetitions' => 1]);
        $experiment = ResearchExperiment::query()->sole();
        (new PrepareResearchExperimentJob($experiment->id))->handle(app(PrepareResearchExperiment::class));
        $this->post($base.'/'.$experiment->id.'/start')->assertSessionHasNoErrors();
        $this->post($base.'/'.$experiment->id.'/start')->assertSessionHasErrors('experiment');
        Queue::assertPushed(RunResearchExperimentBatchJob::class, 1);
        $this->assertSame('queued', $experiment->fresh()->status);
    }

    public function test_unfrozen_dataset_is_rejected_and_experiment_ids_are_project_scoped(): void
    {
        $fixture = $this->researchFixture();
        $project = $fixture['revision']->project;
        $this->actingAs($project->owner);
        $dataset = (new ResearchDataset)->forceFill(['project_id' => $project->id, 'project_revision_id' => $fixture['revision']->id,
            'dataset_id' => 'draft', 'dataset_version' => '0.1.0', 'split' => 'pilot', 'status' => 'draft', 'questions' => []]);
        $dataset->save();
        $this->post('/projects/'.$project->id.'/research/experiments', ['dataset_id' => $dataset->id, 'repetitions' => 1])
            ->assertSessionHasErrors('dataset_id');
        $other = $project->owner->projects()->create(['name' => 'Other', 'slug' => 'other']);
        $experiment = (new ResearchExperiment)->forceFill(['project_id' => $project->id, 'research_dataset_id' => $dataset->id,
            'owner_id' => $project->user_id, 'dataset_sha256' => str_repeat('a', 64), 'revision_fingerprints' => [],
            'repetitions' => 1, 'status' => 'ready']);
        $experiment->save();
        $this->get('/projects/'.$other->id.'/research/experiments/'.$experiment->id)->assertNotFound();
    }
}
