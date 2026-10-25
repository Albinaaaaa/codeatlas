<?php

namespace Tests\Feature\Research;

use App\Models\CodeFile;
use App\Research\DatasetQuestion;
use App\Research\DatasetValidator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ResearchFixture;
use Tests\RefreshDatabase;
use Tests\TestCase;

class DatasetValidatorTest extends TestCase
{
    use RefreshDatabase, ResearchFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeResearchStorage();
    }

    public function test_valid_synthetic_pilot_and_evaluation_datasets_are_read_only(): void
    {
        $fixture = $this->researchFixture();
        $row = $fixture['row'];
        $before = CodeFile::query()->get()->toArray();
        foreach (['pilot', 'evaluation'] as $split) {
            $row['split'] = $split;
            $path = $this->datasetFile([$row]);
            $report = app(DatasetValidator::class)->validate($path);
            $this->assertSame([1, 1, 0, []], [$report['total'], $report['valid'], $report['invalid'], $report['errors']]);
            $this->assertSame(hash_file('sha256', $path), $report['dataset_sha256']);
            $this->assertInstanceOf(DatasetQuestion::class, $report['questions'][0]);
            $this->assertSame($split, $report['questions'][0]->split);
            $this->assertSame(['revisionId' => $fixture['revision']->id, 'question' => $row['question'], 'method' => 'keyword', 'model' => 'qwen2.5-coder:1.5b'],
                $report['questions'][0]->inferenceArguments('keyword', 'qwen2.5-coder:1.5b'));
            $this->assertSame(0, Artisan::call('thesis:dataset:validate', ['path' => $path]));
            $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(1, $json['valid']);
            $this->assertArrayNotHasKey('questions', $json);
            $this->assertStringNotContainsString($row['reference_answer'], Artisan::output());
        }
        $this->assertSame($before, CodeFile::query()->get()->toArray());
        Http::assertNothingSent();
    }

    #[DataProvider('invalidFields')]
    public function test_missing_and_invalid_fields_are_reported_without_echoing_private_values(string $field, mixed $value): void
    {
        $fixture = $this->researchFixture();
        $row = $fixture['row'];
        data_set($row, $field, $value);
        $row['reference_answer'] = $field === 'reference_answer' ? $value : 'PRIVATE_REFERENCE_SENTINEL';
        $path = $this->datasetFile([$row]);
        $report = app(DatasetValidator::class)->validate($path);
        $this->assertSame(1, $report['invalid']);
        $this->assertSame([], $report['questions']);
        $this->assertSame(1, $report['errors'][0]['line']);
        $this->assertSame(1, Artisan::call('thesis:dataset:validate', ['path' => $path]));
        $this->assertStringNotContainsString('PRIVATE_REFERENCE_SENTINEL', Artisan::output());
    }

    public static function invalidFields(): array
    {
        return [
            ['question_id', null], ['dataset_id', []], ['dataset_version', '1'], ['dataset_version', '01.0.0'],
            ['split', 'final'], ['revision_id', '1'], ['revision_id', 0], ['revision_fingerprint', str_repeat('g', 64)],
            ['category', null], ['question', []], ['reference_answer', '  '], ['required_facts', []], ['required_facts.0', ' '],
            ['evidence', []], ['evidence.0.path', null], ['evidence.0.start_line', '3'], ['evidence.0.start_line', 0],
            ['evidence.0.end_line', 2], ['verification', 'invalid'], ['verification.verified', false], ['verification.method', 'ai'],
            ['verification.verified_by', ''], ['verification.verified_at', 'tomorrow'], ['verification.verified_at', '2026-02-30T00:00:00Z'],
        ];
    }

    public function test_duplicate_ids_and_mixed_dataset_metadata_block_all_questions(): void
    {
        $fixture = $this->researchFixture();
        $row = $fixture['row'];
        $duplicate = app(DatasetValidator::class)->validate($this->datasetFile([$row, $row]));
        $this->assertSame([2, 0, 2], [$duplicate['total'], $duplicate['valid'], $duplicate['invalid']]);
        $this->assertSame([1, 2], array_column($duplicate['errors'], 'line'));
        $this->assertSame([$row['question_id'], $row['question_id']], array_column($duplicate['errors'], 'question_id'));
        foreach (['dataset_id' => 'other', 'dataset_version' => '2.0.0', 'split' => 'evaluation'] as $field => $value) {
            $other = [...$row, 'question_id' => 'synthetic.answer.002', $field => $value];
            $report = app(DatasetValidator::class)->validate($this->datasetFile([$row, $other]));
            $this->assertSame([2, 1, 1, []], [$report['total'], $report['valid'], $report['invalid'], $report['questions']]);
        }
    }

    public function test_wrong_fingerprint_missing_paths_and_invalid_evidence_ranges_fail(): void
    {
        $fixture = $this->researchFixture();
        foreach ([['revision_fingerprint', str_repeat('0', 64)], ['revision_id', 2147483647],
            ['evidence.0.path', 'missing.php'], ['evidence.0.end_line', 100], ['evidence.0.path', '../answer.php']] as [$field, $value]) {
            $row = $fixture['row'];
            data_set($row, $field, $value);
            $report = app(DatasetValidator::class)->validate($this->datasetFile([$row]));
            $this->assertSame(1, $report['invalid']);
        }
        $row = $fixture['row'];
        $row['evidence'][0]['path'] = 'missing.php';
        $this->assertSame(1, app(DatasetValidator::class)->validate($this->datasetFile([$row]))['invalid']);
        file_put_contents($fixture['root'].'/answer.php', '<?php // tampered snapshot');
        $this->assertSame(1, app(DatasetValidator::class)->validate($fixture['path'])['invalid']);
    }

    public function test_symbolic_links_and_malformed_or_empty_jsonl_are_rejected(): void
    {
        $fixture = $this->researchFixture();
        unlink($fixture['root'].'/answer.php');
        $this->assertTrue(symlink(base_path('tests/Fixtures/ResearchDataset/repository/answer.php'), $fixture['root'].'/answer.php'));
        $this->assertSame(1, app(DatasetValidator::class)->validate($fixture['path'])['invalid']);
        foreach (['', "\n", "not JSON\n", "[]\n", "{\"question\":\"\xFF\"}\n"] as $raw) {
            file_put_contents($fixture['path'], $raw);
            $this->assertSame(1, Artisan::call('thesis:dataset:validate', ['path' => $fixture['path']]));
            $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertNotEmpty($json['errors']);
        }
        $this->assertSame(1, Artisan::call('thesis:dataset:validate', ['path' => '/missing/dataset.jsonl']));
    }
}
