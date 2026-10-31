<?php

namespace App\Actions\Research;

use App\Models\Project;
use App\Models\ProjectRevision;
use App\Models\ResearchDataset;
use App\Models\User;
use App\RepositoryScanning\ReadSnapshotEvidence;
use App\Research\DatasetValidator;
use App\Research\ResearchFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;

final class ManageResearchDataset
{
    public function __construct(private readonly DatasetValidator $validator, private readonly ReadSnapshotEvidence $evidence) {}

    /** @param array<string, mixed> $data */
    public function create(Project $project, array $data, ?UploadedFile $upload): ResearchDataset
    {
        $revision = $project->revisions()->whereKey($data['revision_id'])->whereHas('indexRuns', fn ($q) => $q->where('status', 'completed'))->first();
        if ($revision === null) {
            throw ValidationException::withMessages(['revision_id' => __('ui.research.invalid_revision')]);
        }
        $fingerprint = $this->fingerprint($revision);
        try {
            $this->evidence->root($revision, $fingerprint);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['revision_id' => __('ui.research.invalid_revision')]);
        }
        $raw = $upload?->get();
        $rows = [];
        if ($raw !== null) {
            try {
                foreach (preg_split('/\r\n|\n|\r/', rtrim($raw, "\r\n")) ?: [] as $line => $json) {
                    $row = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
                    if (! is_array($row) || array_is_list($row)) {
                        throw new JsonException;
                    }
                    $rows[] = $row;
                }
            } catch (JsonException) {
                throw ValidationException::withMessages(['upload' => __('ui.research.invalid_jsonl', ['line' => $line + 1])]);
            }
            if ($rows === [] || count($rows) > 500) {
                throw ValidationException::withMessages(['upload' => __('ui.research.import_limit')]);
            }
            $data = array_intersect_key($rows[0], array_flip(['dataset_id', 'dataset_version', 'split']));
        }
        Validator::make($data, [
            'dataset_id' => ['required', 'string', 'max:128', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/'],
            'dataset_version' => ['required', 'string', 'max:32', 'regex:/\A(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)\z/'],
            'split' => ['required', 'in:pilot,evaluation'],
        ])->validate();
        foreach ($rows as $index => &$row) {
            if (($row['revision_id'] ?? null) !== (int) $revision->id || ($row['revision_fingerprint'] ?? null) !== $fingerprint
                || ($row['dataset_id'] ?? null) !== $data['dataset_id'] || ($row['dataset_version'] ?? null) !== $data['dataset_version']
                || ($row['split'] ?? null) !== $data['split']) {
                throw ValidationException::withMessages(['upload' => __('ui.research.import_scope', ['line' => $index + 1])]);
            }
            // Imported attestations are traceable in the original file, never trusted as a web user's action.
            $row['verification'] = ['verified' => false];
            $row = $this->draftRow($row);
        }
        unset($row);
        $path = $raw === null ? null : 'datasets/'.Str::uuid().'/original.jsonl';
        if ($path !== null) {
            Storage::disk('thesis_research')->put($path, $raw);
        }
        try {
            return DB::transaction(function () use ($project, $revision, $data, $rows, $path, $raw): ResearchDataset {
                $dataset = new ResearchDataset;
                $dataset->forceFill([
                    'project_id' => $project->id, 'project_revision_id' => $revision->id,
                    'dataset_id' => $data['dataset_id'], 'dataset_version' => $data['dataset_version'], 'split' => $data['split'],
                    'status' => 'draft', 'lock_version' => 1, 'questions' => $rows,
                    'original_path' => $path, 'original_sha256' => $raw === null ? null : hash('sha256', $raw),
                ])->save();

                return $dataset;
            });
        } catch (\Throwable $error) {
            if ($path !== null) {
                Storage::disk('thesis_research')->delete($path);
            }
            throw $error;
        }
    }

    /** @param array<string, mixed> $data */
    public function change(ResearchDataset $dataset, int $version, string $operation, User $reviewer, ?int $index = null, array $data = []): void
    {
        DB::transaction(function () use ($dataset, $version, $operation, $reviewer, $index, $data): void {
            $locked = ResearchDataset::query()->lockForUpdate()->findOrFail($dataset->id);
            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['dataset' => __('ui.research.frozen_error')]);
            }
            if ($locked->lock_version !== $version) {
                throw ValidationException::withMessages(['dataset' => __('ui.research.conflict')]);
            }
            $rows = $locked->questionRows();
            $locked->validation_report = null;
            if ($operation === 'add') {
                if (count($rows) >= 500) {
                    throw ValidationException::withMessages(['dataset' => __('ui.research.import_limit')]);
                }
                $rows[] = $this->draftRow([
                    'dataset_id' => $locked->dataset_id, 'dataset_version' => $locked->dataset_version, 'split' => $locked->split,
                    'revision_id' => (int) $locked->project_revision_id, 'revision_fingerprint' => $this->fingerprint($locked->revision),
                ]);
            } elseif ($operation === 'edit' || $operation === 'verify') {
                abort_if($index === null || ! isset($rows[$index]), 404);
                if ($operation === 'edit') {
                    $rows[$index] = $this->draftRow(array_replace($rows[$index], array_intersect_key($data, array_flip([
                        'question_id', 'category', 'question', 'reference_answer', 'required_facts', 'evidence',
                    ]))));
                    $rows[$index]['verification'] = ['verified' => false];
                } else {
                    $rows[$index]['verification'] = ['verified' => true, 'method' => 'manual',
                        'verified_by' => 'user:'.$reviewer->id, 'verified_at' => now()->format('Y-m-d\TH:i:sP')];
                    $report = $this->check([$rows[$index]]);
                    if ($report['errors'] !== []) {
                        $errors = [];
                        foreach ($report['errors'] as $error) {
                            $errors[$this->errorField($error['message'])][] = $this->errorMessage($error['message']);
                        }
                        throw ValidationException::withMessages($errors);
                    }
                }
            } elseif ($operation === 'validate' || $operation === 'freeze') {
                $report = $this->check($rows, $operation === 'freeze');
                $locked->validation_report = $report;
                if ($operation === 'freeze' && $report['errors'] === []) {
                    $locked->status = 'frozen';
                    $locked->frozen_path = $report['frozen_path'];
                    $locked->frozen_sha256 = $report['dataset_sha256'];
                    $locked->frozen_at = now();
                    unset($report['frozen_path']);
                    $locked->validation_report = $report;
                }
            }
            $locked->questions = $rows;
            $locked->lock_version++;
            $locked->save();
        });
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function draftRow(array $row): array
    {
        foreach (['question_id', 'category', 'question', 'reference_answer'] as $field) {
            $row[$field] ??= '';
        }
        $row['required_facts'] ??= [];
        $row['evidence'] ??= [];
        $row['verification'] ??= ['verified' => false];
        Validator::make($row, [
            'question_id' => ['nullable', 'string', 'max:128'], 'category' => ['nullable', 'string', 'max:64'],
            'question' => ['nullable', 'string', 'max:16000'], 'reference_answer' => ['nullable', 'string', 'max:32000'],
            'required_facts' => ['array', 'list', 'max:100'], 'required_facts.*' => ['nullable', 'string', 'max:8000'],
            'evidence' => ['array', 'list', 'max:100'], 'evidence.*' => ['array:path,start_line,end_line'],
            'evidence.*.path' => ['present', 'nullable', 'string', 'max:1024'],
            'evidence.*.start_line' => ['required', 'integer', 'min:1'],
            'evidence.*.end_line' => ['required', 'integer', 'min:1'],
        ])->validate();
        foreach (['question_id', 'category', 'question', 'reference_answer'] as $field) {
            $row[$field] = $row[$field] ?? '';
        }
        $row['required_facts'] = array_map(fn ($fact) => $fact ?? '', $row['required_facts']);
        $row['evidence'] = array_map(fn ($item) => ['path' => $item['path'] ?? '', 'start_line' => (int) $item['start_line'], 'end_line' => (int) $item['end_line']], $row['evidence']);

        return $row;
    }

    /** @param list<array<string, mixed>> $rows
     * @return array{total: int, valid: int, invalid: int, dataset_sha256: string, errors: list<array{line: int, question_id: ?string, message: string}>, frozen_path?: string}
     */
    private function check(array $rows, bool $freeze = false): array
    {
        $raw = $rows === [] ? '' : implode("\n", array_map(fn (array $row): string => json_encode($row, ResearchFiles::JSON_FLAGS), $rows))."\n";
        $path = 'datasets/'.Str::uuid().'/validated.jsonl';
        $disk = Storage::disk('thesis_research');
        $disk->put($path, $raw);
        $keep = false;
        try {
            $report = $this->validator->validate($disk->path($path));
            unset($report['questions']);
            if ($freeze && $report['errors'] === []) {
                $report['frozen_path'] = $path;
                $keep = true;
            }

            return $report;
        } finally {
            if (! $keep) {
                $disk->delete($path);
            }
        }
    }

    public function fingerprint(ProjectRevision $revision): string
    {
        $metadata = $revision->getAttribute('metadata');

        return is_array($metadata) && is_string($metadata['snapshot']['fingerprint'] ?? null) ? $metadata['snapshot']['fingerprint'] : '';
    }

    public function errorField(string $message): string
    {
        return preg_match('/^Invalid or missing field: ([a-z0-9_.]+)\.$/', $message, $match) ? $match[1] : 'dataset';
    }

    public function errorMessage(string $message): string
    {
        $field = $this->errorField($message);

        if ($field !== 'dataset') {
            return __('ui.research.invalid_field', ['field' => $field]);
        }

        return __('ui.research.'.match (true) {
            str_contains($message, 'Duplicate') => 'duplicate',
            str_contains($message, 'verification'), str_contains($message, 'Verification') => 'manual_required',
            str_contains($message, 'at least one') => 'empty_validation',
            str_contains($message, 'snapshot'), str_contains($message, 'Snapshot'), str_contains($message, 'Evidence') => 'snapshot_invalid',
            default => 'validation_general',
        });
    }
}
