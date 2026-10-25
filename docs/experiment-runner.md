# Sequential experiment runner — Phase 08

Actual CLI signature:

```text
thesis:run {datasetPath} {--repetitions=} {--dry-run} {--limit=} {--resume}
```

`--repetitions` defaults to `thesis.repetitions` (currently 3). Both repetitions and limit must be positive integers. The runner requires exactly the existing three Qwen models and three retrieval methods from `config/thesis.php`; it does not add methods or change prompt/generation rules.

## Pilot procedure

Use a private, frozen JSONL file with 1–2 manually reviewed questions satisfying [dataset validation](research-datasets-and-records.md). Do not use the synthetic test fixture as research evidence. Apply application migrations before saving attempts; implementation verification migrates only `codeatlas_test`.

```sh
php artisan thesis:dataset:validate storage/app/private/research/pilot-v1.jsonl
php artisan thesis:run storage/app/private/research/pilot-v1.jsonl --repetitions=1 --dry-run
```

For two questions the preview contains 18 cases (2 × 3 models × 3 methods × 1 repetition); for two repetitions it contains 36. All contexts are validated using the existing inference preparation path, including missing chunks and budget errors. Dry-run performs **no HTTP requests, inference or database writes**. It previews the entire matrix even when limit is set. It prints question IDs and source/prompt hashes, not question text, references, prompts or source content.

Only after reviewing the preview, with a real verified pilot explicitly supplied and Ollama operational:

```sh
php artisan thesis:run storage/app/private/research/pilot-v1.jsonl --repetitions=1 --limit=2 --resume
php artisan thesis:run storage/app/private/research/pilot-v1.jsonl --repetitions=1 --resume
```

The second command executes every remaining case, potentially including the 14B model; run it only when ready. Limit counts **actual attempts**, including failures, not questions or skipped successes. No real experiments are started during implementation, no models are downloaded, and no final evaluation dataset is fabricated. There are no scans, chunk regeneration, hidden retries, warm-up generations or additional measured cases. If the research campaign requires the protocol's unmeasured warm-up, perform and document it separately before the measured pilot. Stop competing inference workloads manually.

## Identity, ordering and model provenance

`experiment_id` (stored as `experiment_key`) is SHA-256 over runner version, exact dataset checksum, full captured thesis configuration, endpoint, repetition count and complete prepared matrix including revision fingerprints and prompt hashes. It intentionally excludes dry-run, resume and invocation limit. Configuration object keys are canonicalized using the Phase 07 identity helper. Changing dataset bytes, protocol, generation parameters, prompt/context or repetition count creates a different experiment. Increasing repetitions therefore starts a different campaign rather than extending an existing one.

Cases are ordered deterministically by SHA-256 of the configured seed and case metadata, avoiding process-global random state and model grouping. The seed is also passed unchanged to inference; repetitions measure variability under the same generation configuration. No statistical aggregation or grading is performed.

Before actual execution, the runner reads `/api/version` and `/api/tags` once, using bounded metadata requests. Available version/digests are frozen for that invocation and passed to `RecordExperiment`; missing observations are null. The per-repetition **`case_key`** incorporates experiment identity, question/revision, model, method, repetition, prompt hash and observed model provenance. A changed model digest or Ollama version yields new cases and cannot reuse incompatible successes. The configuration experiment ID groups these model observations; the report includes each observation and exact case keys.

Dry-run does not observe model metadata, so `case_identity_final=false`: its case hashes are provisional and it does not claim to know historical completeness. Its configuration experiment ID and prepared matrix match execution under the same dataset/configuration. Actual execution sets `case_identity_final=true`; `provenance_verified` explicitly indicates whether both version and digest were observed. When provenance is unavailable, resume never skips an old success, even with the same null-provenance key. Freeze model installation and configuration for the campaign; tags must not be changed during an invocation.

Phase 07's `run_key` remains the logical inference identity shared across repetitions. Phase 08's separate `case_key` includes repetition; each actual recorded attempt still gets a fresh UUID. Two nullable indexed columns on the existing table are sufficient; historical records stay unchanged and are not silently assumed to belong to a runner campaign. The encrypted payload additionally freezes the execution keys, runner version, actual requested repetition count (including CLI overrides) and total planned cases, separately from the original protocol defaults.

## Resume, failures and completeness

`--resume` skips cases with a saved successful attempt and identical case identity with verified model provenance. Failed and incomplete attempts are retained, and a subsequent invocation retries each unresolved case at most once, inserting a new UUID. Without resume the invocation deliberately attempts cases again. There are no automatic retries within one invocation. HTTP requests occur outside record-insert transactions.

Linux SIGINT/SIGTERM requests a cooperative stop between cases; an in-flight request is allowed to finish and be saved. Exit code 130 identifies this stop. A hard kill or crash before an insert may leave a case pending; its HTTP delivery/result cannot be inferred or fabricated. On restart it is eligible for a new attempt. Previously saved attempts remain durable. Run one CLI process per campaign; distributed/concurrent orchestration is outside scope.

Progress JSON goes to stderr; the final structured completeness report goes to stdout. Neither contains source code, answers or reference data. Only the allowlisted `DatasetQuestion::inferenceArguments()` enters inference. The exact prompt/context, answer, metrics and provenance are saved through the encrypted Phase 07 payload. Configuration/context drift is rejected rather than silently recording a result against an incompatible planned case.

Example completeness report after a two-attempt pilot on two questions (abbreviated; hashes/case list omitted):

```json
{
  "questions": 2,
  "repetitions": 1,
  "total": 18,
  "attempted": 2,
  "completed": 2,
  "failed": 0,
  "incomplete": 0,
  "skipped": 0,
  "pending": 16,
  "remaining": 16,
  "interrupted": false
}
```

`completed` means cases with successful results; `failed`/`incomplete` identify unresolved cases with recorded attempts, `pending` identifies cases without a result, and `remaining = total - completed` includes all unresolved cases. Every case is listed with its ID and status. `skipped` and `attempted` count this invocation only. A dry-run reports all cases pending because it does not contact Ollama to resolve model identities.

Exit codes: 0 for a valid dry-run or fully successful matrix; 2 for an unfinished matrix (including intentional limit, failures or incomplete answers); 1 for validation/preparation/storage/unexpected failure; 130 for cooperative interruption. No resume state is stored in public files or logs. Research evaluation, exports, dashboards and Phase 09 are not implemented.
