# Research datasets and experiment records — Phase 07

This phase validates dataset files and inserts individual immutable attempt records. It does not execute a matrix, repeat/retry/resume experiments, grade answers, export results or expose a web interface. The final research dataset is still absent; `thesis.status` and the intentionally empty dataset configuration are unchanged.

## JSONL format and validation

Use UTF-8, exactly one JSON object per physical line, without blank lines. Every file has one `dataset_id`, semantic `dataset_version` (`major.minor.patch`, at most 32 characters) and `split`. Use separate files for `pilot` (development/pilot work) and `evaluation` (final held-out evaluation). Do not mix splits in a file. Question IDs must be unique in the entire file; duplicates invalidate both occurrences. IDs contain 1–128 ASCII letters/digits or `._:-`, beginning with a letter/digit. Categories are lowercase identifiers with underscores. No automatic dataset/question identity or reference answer is generated.

Synthetic example (the committed fixture has local revision ID 1; tests replace only that database-local ID with the materialized revision's actual ID):

```jsonl
{"question_id":"synthetic.answer.001","dataset_id":"synthetic-pilot","dataset_version":"1.0.0","split":"pilot","revision_id":1,"revision_fingerprint":"335d222df672b2959f59d56ec2e15555a2086069166af2b4a0bccb1fab063f4c","category":"function_behavior","question":"What does answer return?","reference_answer":"The function returns the integer 42.","required_facts":["The return value is the integer 42."],"evidence":[{"path":"answer.php","start_line":3,"end_line":6},{"path":"gold-location-only.txt","start_line":1,"end_line":1}],"verification":{"verified":true,"method":"manual","verified_by":"synthetic-test-fixture","verified_at":"2026-10-25T00:00:00Z","synthetic":true}}
```

`tests/Fixtures/ResearchDataset` is synthetic test material, not a human-verified final evaluation dataset. Its verification metadata is fixture data. For real datasets, the operator must actually review the answer, required facts and evidence, then attest `verification.verified=true`, `method=manual`, a non-empty reviewer identifier and an ISO-8601 timestamp with timezone. The validator checks this attestation's shape, not the factual correctness of the answer; automated grading is not implemented.

`revision_fingerprint` is the existing lowercase SHA-256 snapshot fingerprint, not a branch name or a hash invented from the question. The validator checks it against `ProjectRevision.identifier` (`snapshot:sha256:...`), snapshot metadata/format and the expected private snapshot location. Evidence paths must remain inside that snapshot, without symlinks, absolute paths or traversal. Each referenced file must exist in the revision's existing inventory and match its indexed content hash. Inclusive one-based line ranges must exist in the actual file; LF, CRLF and CR are counted consistently. Referenced files are read once per validation call and cached as hash/range checks, not repeatedly rescanned for each question. The validator relies on the already resolved immutable snapshot identity; it does not rematerialize or rescan the whole repository.

Actual CLI signature and usage:

```text
thesis:dataset:validate {path}
```

```sh
php artisan thesis:dataset:validate storage/app/private/research/pilot-v1.jsonl
php artisan thesis:dataset:validate storage/app/private/research/evaluation-v1.jsonl
```

The JSON report contains `total`, `valid`, `invalid`, the SHA-256 of the exact JSONL bytes, and errors with dataset line number and safe question ID. It never prints references or source contents. Empty/unreadable/malformed files return exit code 1; valid files return 0. An empty file has zero question rows and a file-level error at line 0. An invalid dataset yields **no executable question objects**, even when some individual rows are valid. Revisions, files and configuration are not modified. A changed JSONL byte sequence has a different dataset checksum; freeze the validated file for a campaign.

## Preventing reference leakage

`DatasetQuestion` keeps evaluation data private. `inferenceArguments(method, model)` returns exactly four allowlisted arguments: revision ID, question text, retrieval method and requested model. Neither reference answers, required facts, evaluation evidence locations nor verification metadata enter retrieval or inference. Independently retrieved repository code may naturally overlap gold evidence; the gold locations are never used to guide selection. `evaluationData()` is an explicit accessor for encrypted record storage/future evaluation, not an inference input. The validation command removes question objects from its public report.

## Durable storage

One new table, `thesis_experiment_attempts`, stores one row per caller-supplied attempt UUID and positive one-based repetition index. Queryable metadata includes project/revision IDs, immutable fingerprint, question ID, dataset ID/version/checksum/split, protocol/prompt versions, method, requested/returned model, prompt SHA-256, status and a stable run key. There are no symbol/chunk foreign keys. The scoped project/revision foreign key restricts accidental deletion of a referenced revision; reindexing and rechunking do not remove records.

The `payload` column is **Laravel `encrypted:array` over text**, using the application's existing encryption key. It contains the frozen dataset question (including references/verification), exact inference prompt and selected source context, chunk stable IDs/path/ranges, answer, captured protocol configuration, effective model options, request flags, estimates, actual metrics, durations and wall times, completion/error data and available model provenance. It is hidden from ordinary model serialization; trusted callers explicitly use `details()` to decrypt it. Database credentials/backups and the application encryption key must remain protected and available for recovery. No prompts/answers are written to public storage or application logs.

`run_key` is SHA-256 over a canonical, project/revision-scoped identity: dataset identity/checksum/split, question ID, revision fingerprint, method, model, exact prompt hash, **captured** configuration, effective generation/request options and supplied frozen model provenance. Object keys are sorted recursively, list order is preserved, and integral floats are normalized so passing a result through JSON does not change identity. Answers, observed timings, completion/error status, repetition index and attempt UUID are excluded. Thus retries/repetitions of the same logical configuration share a run key but retain separate records; a changed seed, prompt, dataset checksum or frozen model identity changes the key. A reused attempt UUID is rejected, never used to overwrite a record.

Record statuses are `successful`, `incomplete`, `failed`. Normal complete responses without a known actual-token budget conflict are successful. Truncation, unknown completion, known token-budget conflicts and genuine partial `done=false` responses are incomplete. Transport/HTTP/metadata/malformed/empty-answer failures are failed. Available metrics are preserved in their original units; missing/invalid failure metrics remain null, never fabricated zeros. Ollama timing fields remain nanoseconds and external timings remain milliseconds.

Phase 06 now carries a private, non-reported exception snapshot for failures after context preparation. `submission_attempted=false` means the model request was not issued (for example, inspection failed); its prompt is a prepared prompt, not a claim the server received it. A connection failure after attempting generation cannot prove delivery. Partial response fields are preserved when available, but raw server error bodies and transport exception chains are not retained. Internal prepared-context arguments are marked sensitive for stack traces. Validation/preparation failures without an inference snapshot cannot be saved as if they had a submitted prompt; no unavailable prompt is invented.

The recorder does not fetch model versions/digests or make any HTTP calls. Existing available model/template/system metadata are retained from inference. Callers may supply frozen `ollama_version` and `model_digest` metadata obtained separately; absent values are explicitly null. Do not substitute a previous smoke-test version/digest or guess a value. This allows a future campaign runner to capture provenance once and pass it consistently for successes and failures.

Minimal reusable API (one explicit inference and one insert, not a runner):

```php
$report = $validator->validate($path);
if ($report['errors'] !== []) {
    throw new InvalidArgumentException('Dataset is invalid.');
}
$question = $report['questions'][0];
try {
    $outcome = $inference->ask(...$question->inferenceArguments($method, $model));
} catch (OllamaInferenceException $exception) {
    $outcome = $exception;
}
$attempt = $recorder->record($question, $outcome, $attemptUuid, $repetitionIndex, $frozenModelProvenance);
$privateDetails = $attempt->fresh()->details();
```

The recorder verifies question/revision/fingerprint isolation, prompt SHA-256, captured version consistency and selected-context content hashes before inserting. Neither reference data nor current database symbol IDs are used in prompts or permanent result references. The `thesis:ask` command remains an inspection/inference command and does not save records automatically. There are no new experiment routes, dataset tables, schedulers, loops over combinations, resume logic or grading services.

## Stored synthetic example

The following abbreviated record was inserted and reloaded from `codeatlas_test` using the fixture and Laravel HTTP fakes. Its transaction was then rolled back and private example files removed. Token counts/timings here are **fake test inputs**, not real model measurements. The raw database payload contained encrypted ciphertext, ordinary model serialization hid it, and decrypted context matched the original inference context.

```json
{
  "run_key": "79ded5bcbe11c16371d5c6528008f7bf4a98995b4b81905d6a99d80643b1c2c2",
  "attempt_id": "997612b7-62d3-493e-83d2-c8fae24b5423",
  "repetition_index": 1,
  "question_id": "synthetic.answer.001",
  "dataset_id": "synthetic-pilot",
  "dataset_version": "1.0.0",
  "dataset_sha256": "7218165093e606769bbac557bd7543e643c08f7deedc2f0154eacaf9281c7322",
  "split": "pilot",
  "revision_fingerprint": "335d222df672b2959f59d56ec2e15555a2086069166af2b4a0bccb1fab063f4c",
  "protocol_version": "1.1.0",
  "method": "keyword",
  "requested_model": "qwen2.5-coder:1.5b",
  "status": "successful",
  "prompt_sha256": "b3bddb9346b6f71e61c96124c4e4909316518491c561c5da9e483a4f58a82748"
}
```

Its encrypted details preserve answer `42`, estimated prompt tokens `889`, fake actual input/output counts `100`/`12`, all six fake Ollama timing/count fields, external wall times and the exact source chunks `answer.php:3-6` and `answer.php:1-2`. Model digest and Ollama version are null because those observations were not supplied; template metadata from the fake response is retained. Real datasets must be authored/reviewed and frozen by the operator before a future final experiment campaign. Apply the new migration in the intended application database before using records there; verification in this phase migrated only `codeatlas_test`.
