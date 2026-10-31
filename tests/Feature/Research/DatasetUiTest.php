<?php

namespace Tests\Feature\Research;

use App\Models\ResearchDataset;
use App\Models\User;
use App\Research\DatasetValidator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\ResearchFixture;
use Tests\RefreshDatabase;
use Tests\TestCase;

class DatasetUiTest extends TestCase
{
    use RefreshDatabase;
    use ResearchFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeResearchStorage();
        config(['inertia.ssr.enabled' => false]);
        Storage::fake('thesis_research');
    }

    /** @return array{dataset: ResearchDataset, base: string, fixture: array<string, mixed>} */
    private function imported(): array
    {
        $fixture = $this->researchFixture();
        $project = $fixture['revision']->project;
        $this->actingAs($project->owner);
        $base = '/projects/'.$project->id.'/research/datasets';
        $raw = file_get_contents($fixture['path']);
        $this->post($base, ['revision_id' => $fixture['revision']->id, 'upload' => UploadedFile::fake()->createWithContent('draft.jsonl', $raw)])
            ->assertSessionHasNoErrors()->assertRedirect();
        $dataset = ResearchDataset::query()->where('project_id', $project->id)->sole();

        return ['dataset' => $dataset, 'base' => $base.'/'.$dataset->id, 'fixture' => $fixture];
    }

    public function test_owner_can_create_incomplete_draft_and_add_edit_questions(): void
    {
        $fixture = $this->researchFixture();
        $project = $fixture['revision']->project;
        $this->actingAs($project->owner);
        $base = '/projects/'.$project->id.'/research/datasets';
        $this->get($base)->assertOk()->assertInertia(fn (Assert $page) => $page->component('projects/research/datasets/index')->has('revisions', 1));
        $this->post($base, ['revision_id' => $fixture['revision']->id, 'dataset_id' => 'draft', 'dataset_version' => '0.1.0', 'split' => 'pilot'])
            ->assertSessionHasNoErrors();
        $dataset = ResearchDataset::query()->where('project_id', $project->id)->sole();
        $this->post($base.'/'.$dataset->id.'/questions', ['lock_version' => 1])->assertSessionHasNoErrors();
        $this->assertCount(1, $dataset->fresh()->questionRows());
        $this->patch($base.'/'.$dataset->id.'/questions/0', [
            'lock_version' => 2, 'question_id' => 'q1', 'category' => '', 'question' => 'Draft question',
            'reference_answer' => '', 'required_facts' => [], 'evidence' => [],
        ])->assertSessionHasNoErrors();
        $this->assertSame('Draft question', $dataset->fresh()->questionRows()[0]['question']);
        $this->assertFalse($dataset->fresh()->questionRows()[0]['verification']['verified']);
        $this->get($base.'/'.$dataset->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('projects/research/datasets/show')->where('dataset.questions.0.question', 'Draft question')
            ->missing('dataset.original_path')->missing('dataset.frozen_path'));
    }

    public function test_import_preserves_original_bytes_and_requires_fresh_manual_verification(): void
    {
        ['dataset' => $dataset, 'fixture' => $fixture, 'base' => $base] = $this->imported();
        $raw = file_get_contents($fixture['path']);
        $this->assertSame($raw, Storage::disk('thesis_research')->get($dataset->original_path));
        $this->assertSame(hash('sha256', $raw), $dataset->original_sha256);
        $this->assertFalse($dataset->questionRows()[0]['verification']['verified']);
        $this->post($base.'/questions/0/verify', ['lock_version' => 1, 'confirmed' => true, 'verified_by' => 'forged', 'verified_at' => '2000-01-01T00:00:00Z'])->assertSessionHasNoErrors();
        $verification = $dataset->fresh()->questionRows()[0]['verification'];
        $this->assertTrue($verification['verified']);
        $this->assertSame('user:'.$fixture['revision']->project->user_id, $verification['verified_by']);
        $this->assertSame(now()->format('Y-m-d'), substr($verification['verified_at'], 0, 10));
        $row = $fixture['row'];
        $this->patch($base.'/questions/0', [...$row, 'lock_version' => 2, 'reference_answer' => 'Changed proposal'])->assertSessionHasNoErrors();
        $this->assertSame(['verified' => false], $dataset->fresh()->questionRows()[0]['verification']);
        $this->assertSame($raw, Storage::disk('thesis_research')->get($dataset->original_path));
    }

    public function test_unverified_pilot_import_is_not_automatically_approved(): void
    {
        $fixture = $this->researchFixture();
        $row = $fixture['row'];
        $row['verification'] = ['verified' => false, 'method' => 'ai_proposed', 'status' => 'draft'];
        $project = $fixture['revision']->project;
        $this->actingAs($project->owner)->post('/projects/'.$project->id.'/research/datasets', [
            'revision_id' => $fixture['revision']->id,
            'upload' => UploadedFile::fake()->createWithContent('draft.jsonl', json_encode($row)."\n"),
        ])->assertSessionHasNoErrors();
        $dataset = ResearchDataset::query()->where('project_id', $project->id)->sole();
        $this->assertFalse($dataset->questionRows()[0]['verification']['verified']);
    }

    public function test_authentication_ownership_and_nested_dataset_scope_are_enforced(): void
    {
        ['dataset' => $dataset, 'base' => $base] = $this->imported();
        auth()->forgetGuards();
        $this->get($base)->assertRedirect('/login');
        $other = User::factory()->create();
        $this->actingAs($other)->get($base)->assertForbidden();
        $this->post($base.'/freeze', ['lock_version' => 1])->assertForbidden();
        $this->get($base.'/questions/0/evidence/0')->assertForbidden();
        $project = $other->projects()->create(['name' => 'Other', 'slug' => 'other']);
        $foreign = '/projects/'.$project->id.'/research/datasets/'.$dataset->id;
        $this->get($foreign)->assertNotFound();
        $this->post($foreign.'/questions', ['lock_version' => 1])->assertNotFound();
        $this->get($foreign.'/questions/0/evidence/0')->assertNotFound();
    }

    public function test_foreign_revision_and_imported_revision_or_fingerprint_are_rejected(): void
    {
        $fixture = $this->researchFixture();
        $other = User::factory()->create();
        $project = $other->projects()->create(['name' => 'Other', 'slug' => 'other']);
        $this->actingAs($other)->post('/projects/'.$project->id.'/research/datasets', [
            'revision_id' => $fixture['revision']->id, 'dataset_id' => 'foreign', 'dataset_version' => '1.0.0', 'split' => 'pilot',
        ])->assertSessionHasErrors('revision_id');
        $ownerProject = $fixture['revision']->project;
        $this->actingAs($ownerProject->owner);
        foreach (['revision_id' => 999999, 'revision_fingerprint' => str_repeat('0', 64)] as $key => $value) {
            $row = [...$fixture['row'], $key => $value];
            $this->post('/projects/'.$ownerProject->id.'/research/datasets', [
                'revision_id' => $fixture['revision']->id, 'upload' => UploadedFile::fake()->createWithContent('draft.jsonl', json_encode($row)."\n"),
            ])->assertSessionHasErrors('upload');
        }
        $this->assertSame(0, ResearchDataset::query()->where('project_id', $ownerProject->id)->count());
    }

    public function test_invalid_jsonl_and_unindexed_revisions_are_rejected(): void
    {
        $fixture = $this->researchFixture();
        $project = $fixture['revision']->project;
        $this->actingAs($project->owner);
        $base = '/projects/'.$project->id.'/research/datasets';
        $this->post($base, ['revision_id' => $fixture['revision']->id, 'upload' => UploadedFile::fake()->createWithContent('bad.jsonl', "{broken}\n")])->assertSessionHasErrors('upload');
        $fixture['revision']->indexRuns()->update(['status' => 'running']);
        $this->post($base, ['revision_id' => $fixture['revision']->id, 'dataset_id' => 'draft', 'dataset_version' => '1.0.0', 'split' => 'pilot'])->assertSessionHasErrors('revision_id');
    }

    public function test_validation_errors_and_explicit_attestation_are_required_before_freeze(): void
    {
        ['dataset' => $dataset, 'base' => $base] = $this->imported();
        $this->post($base.'/questions/0/verify', ['lock_version' => 1])->assertSessionHasErrors('confirmed');
        $this->post($base.'/validate', ['lock_version' => 1])->assertSessionHasNoErrors();
        $dataset->refresh();
        $this->assertNotEmpty($dataset->validation_report['errors']);
        $this->assertSame(1, $dataset->validation_report['errors'][0]['line']);
        $this->post($base.'/freeze', ['lock_version' => 2])->assertSessionHasNoErrors();
        $this->assertSame('draft', $dataset->fresh()->status);
        $this->assertNull($dataset->fresh()->frozen_path);
        $this->assertCount(1, Storage::disk('thesis_research')->allFiles());
    }

    public function test_freeze_preserves_exact_validated_bytes_hash_and_cli_validator_compatibility(): void
    {
        ['dataset' => $dataset, 'base' => $base] = $this->imported();
        $this->post($base.'/questions/0/verify', ['lock_version' => 1, 'confirmed' => true])->assertSessionHasNoErrors();
        $this->post($base.'/validate', ['lock_version' => 2])->assertSessionHasNoErrors();
        $validatedHash = $dataset->fresh()->validation_report['dataset_sha256'];
        $this->post($base.'/freeze', ['lock_version' => 3])->assertSessionHasNoErrors();
        $dataset->refresh();
        $disk = Storage::disk('thesis_research');
        $raw = $disk->get($dataset->frozen_path);
        $this->assertSame('frozen', $dataset->status);
        $this->assertSame($validatedHash, $dataset->frozen_sha256);
        $this->assertSame(hash('sha256', $raw), $dataset->frozen_sha256);
        $report = app(DatasetValidator::class)->validate($disk->path($dataset->frozen_path));
        $this->assertSame([], $report['errors']);
        $this->assertCount(1, $report['questions']);
        $this->assertSame($dataset->revision->id, $report['questions'][0]->revisionId);
        foreach (['questions', 'validate', 'freeze', 'questions/0/verify'] as $operation) {
            $this->post($base.'/'.$operation, ['lock_version' => 4, 'confirmed' => true])->assertSessionHasErrors('dataset');
        }
        $this->patch($base.'/questions/0', [...$dataset->questionRows()[0], 'lock_version' => 4])->assertSessionHasErrors('dataset');
        $this->assertSame($raw, $disk->get($dataset->frozen_path));
        $this->assertSame(4, $dataset->fresh()->lock_version);
    }

    public function test_stale_edit_verification_and_freeze_never_overwrite_newer_data(): void
    {
        ['dataset' => $dataset, 'fixture' => $fixture, 'base' => $base] = $this->imported();
        $this->patch($base.'/questions/0', [...$fixture['row'], 'lock_version' => 1, 'reference_answer' => 'New answer'])->assertSessionHasNoErrors();
        $this->patch($base.'/questions/0', [...$fixture['row'], 'lock_version' => 1])->assertSessionHasErrors('dataset');
        $this->post($base.'/questions/0/verify', ['lock_version' => 1, 'confirmed' => true])->assertSessionHasErrors('dataset');
        $this->post($base.'/freeze', ['lock_version' => 1])->assertSessionHasErrors('dataset');
        $this->assertSame('New answer', $dataset->fresh()->questionRows()[0]['reference_answer']);
        $this->assertSame('draft', $dataset->fresh()->status);
        $this->assertSame(2, $dataset->fresh()->lock_version);
    }

    public function test_evidence_returns_exact_snapshot_lines_and_rejects_tampering(): void
    {
        ['base' => $base, 'fixture' => $fixture] = $this->imported();
        $url = $base.'/questions/0/evidence/0';
        $this->getJson($url)->assertOk()->assertJsonPath('path', 'answer.php')->assertJsonPath('start_line', 3)
            ->assertHeader('Cache-Control', 'no-store, private');
        $content = file_get_contents($fixture['root'].'/answer.php');
        $expected = array_slice(preg_split('/\r\n|\r|\n/', $content), 2, 4);
        $this->assertSame($expected, $this->getJson($url)->json('lines'));
        file_put_contents($fixture['root'].'/answer.php', $content."\n// tampered");
        $response = $this->getJson($url)->assertUnprocessable();
        $this->assertStringNotContainsString($fixture['root'], $response->getContent());
    }

    public function test_evidence_rejects_traversal_absolute_paths_and_invalid_ranges(): void
    {
        ['base' => $base, 'dataset' => $dataset, 'fixture' => $fixture] = $this->imported();
        foreach ([
            ['path' => '../answer.php', 'start_line' => 1, 'end_line' => 2],
            ['path' => '/etc/passwd', 'start_line' => 1, 'end_line' => 2],
            ['path' => 'C:\\private\\file', 'start_line' => 1, 'end_line' => 2],
            ['path' => 'answer.php', 'start_line' => 4, 'end_line' => 3],
            ['path' => 'answer.php', 'start_line' => 1, 'end_line' => 9999],
        ] as $item) {
            $this->patch($base.'/questions/0', [...$fixture['row'], 'lock_version' => $dataset->fresh()->lock_version, 'evidence' => [$item]])->assertSessionHasNoErrors();
            $this->getJson($base.'/questions/0/evidence/0')->assertUnprocessable();
        }
    }

    public function test_evidence_rejects_symlinks_even_if_target_content_matches(): void
    {
        ['base' => $base, 'fixture' => $fixture] = $this->imported();
        $file = $fixture['root'].'/answer.php';
        $target = $fixture['root'].'/copy.php';
        copy($file, $target);
        unlink($file);
        if (! @symlink($target, $file)) {
            $this->markTestSkipped('Symlinks unavailable.');
        }
        $this->getJson($base.'/questions/0/evidence/0')->assertUnprocessable();
    }
}
