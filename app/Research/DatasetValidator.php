<?php

namespace App\Research;

use App\Models\CodeFile;
use App\Models\ProjectRevision;
use DateTimeImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use JsonException;

final class DatasetValidator
{
    private const ID = '/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/';

    /**
     * @return array{total: int, valid: int, invalid: int, dataset_sha256: string, errors: list<array{line: int, question_id: ?string, message: string}>, questions: list<DatasetQuestion>}
     */
    public function validate(string $path): array
    {
        $path = realpath($path);
        if ($path === false || ! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('Dataset must be a readable local JSONL file.');
        }
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new InvalidArgumentException('Dataset could not be opened.');
        }
        $hash = hash_init('sha256');
        $rows = [];
        $ids = [];
        $problems = [];
        $header = null;
        $revisions = [];
        $files = [];
        $line = 0;
        try {
            while (($raw = fgets($stream)) !== false) {
                $line++;
                hash_update($hash, $raw);
                try {
                    $row = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    $problems[$line][] = 'Expected one valid UTF-8 JSON object per line.';

                    continue;
                }
                if (! is_array($row) || array_is_list($row)) {
                    $problems[$line][] = 'Expected a question object.';

                    continue;
                }
                $rows[$line] = $row;
                $id = $row['question_id'] ?? null;
                if (is_string($id) && preg_match(self::ID, $id)) {
                    if (isset($ids[$id])) {
                        $problems[$ids[$id]][] = 'Duplicate question ID.';
                        $problems[$line][] = 'Duplicate question ID.';
                    } else {
                        $ids[$id] = $line;
                    }
                }
                $messages = $this->fields($row);
                foreach ($messages as $message) {
                    $problems[$line][] = $message;
                }
                if ($messages !== []) {
                    continue;
                }
                $identity = [$row['dataset_id'], $row['dataset_version'], $row['split']];
                $header ??= $identity;
                if ($identity !== $header) {
                    $problems[$line][] = 'Dataset ID, version and split must be consistent within one file.';
                }
                try {
                    $revisionId = $row['revision_id'];
                    if (! array_key_exists($revisionId, $revisions)) {
                        $revisions[$revisionId] = ProjectRevision::query()->find($revisionId);
                    }
                    $revision = $revisions[$revisionId];
                    $metadata = $revision?->getAttribute('metadata');
                    $snapshot = is_array($metadata) ? ($metadata['snapshot'] ?? null) : null;
                    if ($revision === null || ! is_array($snapshot)
                        || ($snapshot['fingerprint'] ?? null) !== $row['revision_fingerprint']
                        || ($snapshot['fingerprint_algorithm'] ?? null) !== 'sha256' || ($snapshot['format_version'] ?? null) !== 1
                        || $revision->identifier !== 'snapshot:sha256:'.$row['revision_fingerprint']
                        || ($snapshot['disk'] ?? null) !== 'repository_snapshots'
                        || ($snapshot['path'] ?? null) !== 'projects/'.$revision->project_id.'/revisions/'.$revision->id) {
                        throw new InvalidArgumentException('Revision fingerprint or private snapshot metadata does not match.');
                    }
                    $disk = Storage::disk('repository_snapshots');
                    $root = $this->inside($disk->path(''), $snapshot['path']);
                    foreach ($row['evidence'] as $evidence) {
                        $key = $revisionId.':'.$evidence['path'];
                        if (! isset($files[$key])) {
                            $absolute = $this->inside($root, $evidence['path']);
                            $file = CodeFile::query()->where('project_id', $revision->project_id)->where('project_revision_id', $revision->id)
                                ->where('path', $evidence['path'])->first();
                            $content = is_file($absolute) && is_readable($absolute) ? file_get_contents($absolute) : false;
                            if ($file === null || $content === false || ! hash_equals($file->content_hash, hash('sha256', $content))) {
                                throw new InvalidArgumentException('Evidence file is missing, unindexed or differs from its indexed hash.');
                            }
                            $breaks = preg_match_all('/\r\n|\r|\n/', $content);
                            $files[$key] = ['lines' => $content === '' ? 0 : $breaks + (str_ends_with($content, "\n") || str_ends_with($content, "\r") ? 0 : 1)];
                        }
                        if ($evidence['end_line'] > $files[$key]['lines']) {
                            throw new InvalidArgumentException('Evidence line range exceeds the snapshot file.');
                        }
                    }
                } catch (InvalidArgumentException $exception) {
                    $problems[$line][] = $exception->getMessage();
                }
            }
            if (! feof($stream)) {
                throw new InvalidArgumentException('Dataset could not be read completely.');
            }
        } finally {
            fclose($stream);
        }
        $sha = hash_final($hash);
        if ($line === 0) {
            $problems[0][] = 'Dataset must contain at least one question.';
        }
        ksort($problems);
        $errors = [];
        foreach ($problems as $number => $messages) {
            $id = $rows[$number]['question_id'] ?? null;
            $id = is_string($id) && preg_match(self::ID, $id) ? $id : null;
            foreach (array_unique($messages) as $message) {
                $errors[] = ['line' => $number, 'question_id' => $id, 'message' => $message];
            }
        }
        $questions = [];
        if ($errors === []) {
            foreach ($rows as $row) {
                $questions[] = new DatasetQuestion($row['dataset_id'], $row['dataset_version'], $row['split'], $sha,
                    $row['question_id'], $row['revision_id'], $row['revision_fingerprint'], $row['question'], $row);
            }
        }
        $invalid = count(array_filter(array_keys($problems), fn (int $number): bool => $number > 0));

        return ['total' => $line, 'valid' => $line - $invalid, 'invalid' => $invalid,
            'dataset_sha256' => $sha, 'errors' => $errors, 'questions' => $questions];
    }

    /** @param array<string, mixed> $row
     * @return list<string>
     */
    private function fields(array $row): array
    {
        $strictInt = static function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_int($value)) {
                $fail('Expected an integer.');
            }
        };
        $validator = Validator::make($row, [
            'question_id' => ['required', 'string', 'regex:'.self::ID],
            'dataset_id' => ['required', 'string', 'regex:'.self::ID],
            'dataset_version' => ['required', 'string', 'max:32', 'regex:/\A(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)\z/'],
            'split' => ['required', 'string', 'in:pilot,evaluation'],
            'revision_id' => ['required', $strictInt, 'integer', 'min:1'],
            'revision_fingerprint' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'category' => ['required', 'string', 'regex:/\A[a-z][a-z0-9_]{0,63}\z/'],
            'question' => ['required', 'string'], 'reference_answer' => ['required', 'string'],
            'required_facts' => ['required', 'array', 'list', 'min:1'], 'required_facts.*' => ['required', 'string'],
            'evidence' => ['required', 'array', 'list', 'min:1'], 'evidence.*' => ['required', 'array'],
            'evidence.*.path' => ['required', 'string'],
            'evidence.*.start_line' => ['required', $strictInt, 'integer', 'min:1'],
            'evidence.*.end_line' => ['required', $strictInt, 'integer', 'gte:evidence.*.start_line'],
            'verification' => ['required', 'array'], 'verification.method' => ['required', 'in:manual'],
            'verification.verified_by' => ['required', 'string'], 'verification.verified_at' => ['required', 'string'],
        ]);
        $errors = array_map(fn (string $field): string => 'Invalid or missing field: '.$field.'.', array_keys($validator->errors()->messages()));
        if (($row['verification']['verified'] ?? null) !== true) {
            $errors[] = 'Manual verification must be explicitly attested with verified=true.';
        }
        $date = $row['verification']['verified_at'] ?? null;
        if (! is_string($date) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})\z/', $date)) {
            $errors[] = 'Verification time must be an ISO-8601 timestamp with timezone.';
        } else {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $date);
            $warnings = DateTimeImmutable::getLastErrors();
            if ($parsed === false || ($warnings !== false && ($warnings['warning_count'] > 0 || $warnings['error_count'] > 0))) {
                $errors[] = 'Verification time is not a valid calendar timestamp.';
            }
        }

        return $errors;
    }

    private function inside(string $root, string $relative): string
    {
        if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '\\') || str_contains($relative, ':')
            || str_starts_with($relative, '/') || array_intersect(explode('/', $relative), ['', '.', '..']) !== []) {
            throw new InvalidArgumentException('Evidence must use a safe snapshot-relative path.');
        }
        $root = realpath($root);
        if ($root === false) {
            throw new InvalidArgumentException('Private snapshot root is unavailable.');
        }
        $candidate = $root;
        foreach (explode('/', $relative) as $segment) {
            $candidate .= DIRECTORY_SEPARATOR.$segment;
            if (is_link($candidate)) {
                throw new InvalidArgumentException('Snapshot evidence must not use symbolic links.');
            }
        }
        $path = realpath($candidate);
        $prefix = $root.DIRECTORY_SEPARATOR;
        if ($path === false || ! str_starts_with(PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path,
            PHP_OS_FAMILY === 'Windows' ? strtolower($prefix) : $prefix)) {
            throw new InvalidArgumentException('Evidence path is missing or outside the private snapshot.');
        }

        return $path;
    }
}
