# Manual evaluation and private research exports — Phase 09

This implementation completes the planned MVP tooling, not the thesis research. Final dataset preparation, verified pilot/evaluation questions, real model execution, independent manual grading and scientific interpretation remain separate researcher tasks. The examples below are synthetic test data, not research findings. No LLM grader, string-similarity correctness estimator, significance test or frontend is implemented.

## Commands and protected paths

Apply the new migration in the intended application database before importing grades. Automated verification uses only `codeatlas_test`. Exact command signatures:

```text
thesis:grade:export {experimentId} {--seed=}
thesis:grade:import {experimentId} {path}
thesis:results:export {experimentId} {datasetPath?} {--repetitions=} {--by-category} {--raw-only}
```

```sh
php artisan thesis:grade:export <experiment-id> --seed=42
php artisan thesis:grade:import <experiment-id> storage/app/private/research/grades.jsonl
php artisan thesis:results:export <experiment-id> storage/app/private/research/pilot-v1.jsonl
php artisan thesis:results:export <experiment-id> storage/app/private/research/pilot-v1.jsonl --by-category
php artisan thesis:results:export <experiment-id> --raw-only
```

Each command prints only paths/counts or a safe error, with exit code 0 on success and 1 on validation/storage failure. Exports create new UUID bundles, never overwrite an earlier export:

```text
storage/app/private/thesis-research/grading/<experiment-id>/<bundle-uuid>/answers.jsonl
storage/app/private/thesis-research/grading/<experiment-id>/<bundle-uuid>/rubric.json
storage/app/private/thesis-research/results/<experiment-id>/<bundle-uuid>/raw.json
storage/app/private/thesis-research/results/<experiment-id>/<bundle-uuid>/raw.jsonl
storage/app/private/thesis-research/results/<experiment-id>/<bundle-uuid>/attempts.csv
storage/app/private/thesis-research/results/<experiment-id>/<bundle-uuid>/summary.json
storage/app/private/thesis-research/results/<experiment-id>/<bundle-uuid>/summary.csv
```

`--raw-only` writes the first three result files and requires no dataset or available snapshots. It archives saved records even when summary validation cannot succeed. Summaries require the exact frozen JSONL dataset and valid immutable evidence; SHA-256, questions, fingerprints, frozen configuration and repetition counts are checked. Existing campaigns use saved protocol/generation configuration, not current application defaults. With zero saved attempts, a matching Phase 08 dry-run proves campaign identity; supply its original `--repetitions=N` override if different from the current default. No inference, scan or chunk generation occurs.

The dedicated `thesis_research` disk is local, private and **not served**. Output destinations cannot be supplied on the CLI. The writer rejects public/served disks, traversal and symlinked destination ancestors; files have private visibility (0600 on the Linux test runtime). There are no download routes. Restrict filesystem/database access and backups; preserve the application encryption key. Grade inputs are explicit local JSONL files, not executable PHP or URLs. Prompts, references and answers are never printed to application logs or command output.

## Blind grading

Give the reviewer **only** `answers.jsonl` and `rubric.json`, not raw/summary exports, console reports, experiment folders or access to the application database. The answers file has exactly these fields per line:

```json
{
  "evaluation_id": "<opaque 64-character HMAC from the actual export>",
  "rubric_version": "1.0.0",
  "question": "What does answer return?",
  "reference_answer": "The function returns the integer 42.",
  "required_facts": ["The return value is the integer 42."],
  "answer": "Відповідь: \"42\".\nЦіле число."
}
```

The identifier is HMAC-SHA-256 over a versioned domain and immutable attempt UUID, keyed by the existing application encryption key. It exposes no attempt/case IDs, model, method, repetition, scores, timings, generation configuration or provenance. It remains stable while the application key and attempt identity remain unchanged. No public mapping file is created: only the trusted application can derive IDs and resolve them against private attempt records. Imported grades store the mapping in a protected database table.

Order is sorted by SHA-256 of the blind ordering seed and opaque ID. The same set/seed produces identical bytes; a different seed reorders the set. The seed defaults to the configured generation seed but is not included in review records. Successful completed attempts are exported, including separate successful repetitions/attempts. Failed/incomplete attempts are excluded from grading and explicitly counted in the command report and unblinded results, never assigned zero correctness. Generated answer text is preserved verbatim: an answer could self-identify a model, so metadata blinding cannot guarantee content-level anonymity.

Rubric **1.0.0**:

| Correctness | Manual judgment |
| --- | --- |
| 0 | Incorrect: the core conclusion is wrong or unsupported. |
| 1 | Partially correct: some supported content, with material omissions/errors. |
| 2 | Correct: required facts are covered, without material errors or unsupported claims. |

The reviewer independently judges every supplied required fact using a boolean in the original order. `true` means correctly supported by the answer; `false` means missing, incorrect or unsupported. Coverage is `count(true) / count(required_facts)`. Correctness is a separate human judgment, never computed from coverage or matching words. Optional notes identify missing facts/unsupported claims. The importer validates types and completeness, not semantic truth or reviewer independence; research procedures must enforce those.

The reviewer creates one grade object per physical JSONL line (the ID placeholder must be replaced with an exported ID):

```json
{"evaluation_id":"<actual exported ID>","rubric_version":"1.0.0","correctness":2,"fact_coverage":[true],"reviewer":"reviewer-01","notes":"Synthetic format example only."}
```

`correctness` must be an integer 0–2, coverage a list of exactly one boolean per frozen fact, reviewer a non-empty string of at most 128 characters, and optional notes a string of at most 8000 characters. Unknown fields, versions, unknown/non-successful IDs, malformed/empty files and duplicate IDs within a file are rejected. The full import is transactional: a conflicting grade rolls back all new grades in that import. Reimporting the identical grade is an explicit idempotent `already_present`, with no timestamp/payload changes; conflicting score, facts, reviewer or notes never overwrite an earlier evaluation. Corrections/adjudication/version migration are deliberate future research procedures, not an implicit update operation.

One new table, `thesis_answer_evaluations`, stores a unique attempt FK, unique blind ID, rubric version, correctness, timestamps and encrypted payload containing fact judgments/reviewer/notes. The payload and mapping FK are hidden from ordinary model serialization. The attempt FK restricts deletion. Original `thesis_experiment_attempts` rows/payloads are never edited by grading or export. References and grades never reach retrieval or Ollama.

## Raw export and CSV safety

`raw.json` contains a versioned envelope with all attempts, including failed/incomplete attempts and retry history. Each record preserves **all original database attributes** (including original encrypted payload ciphertext), the entire decrypted private payload, and the grade with original attributes/decrypted details if present. Exact prompts, context, source locations, reference data, configuration, metrics and errors remain recoverable. `raw.jsonl` contains one corresponding record per line with format version and experiment ID. These are unblinded, private archives; never send them to a blind reviewer.

`attempts.csv` contains experiment/case/run/attempt IDs, question/category, revision fingerprint, model/method/repetition, status, dataset/protocol/prompt versions, prompt/context hashes, stable chunk IDs, estimates and counting method, actual counts, all Ollama durations in nanoseconds, external end-to-end/inference wall times in milliseconds, answer, completion/error information and available grades/coverage. No metric is invented: null values become empty CSV fields. Estimated prompt counts are explicitly marked; actual counts remain separate. A zero-attempt archive has empty raw records/JSONL/CSV, with missing-case rows in summaries.

CSV uses UTF-8, CRLF records, quoted fields, doubled embedded quotes and support for multiline content; the escape parameter is explicitly empty. Potential formula text beginning with `=`, `+`, `-`, `@` (including leading whitespace/BOM), tab/CR/LF receives a leading apostrophe. JSON/JSONL preserve exact original text without this spreadsheet-specific transformation. Do not treat CSV as a byte-exact source archive.

Abbreviated synthetic CSV columns/row (the actual export has all provenance and metric columns):

```csv
model,method,status,actual_input_tokens,actual_output_tokens,correctness,required_fact_coverage,answer
qwen2.5-coder:1.5b,baseline,successful,100,12,2,1,"Відповідь: ""42"".
Ціле число."
```

Corresponding abbreviated JSON record fragment; the real archive additionally preserves all original attributes, prompt/context/configuration and grade details:

```json
{"attributes":{"requested_model":"qwen2.5-coder:1.5b","method":"baseline","status":"successful"},"details":{"inference":{"answer":"Відповідь: \"42\".\nЦіле число.","actual_metrics":{"prompt_eval_count":100,"eval_count":12,"eval_duration":700000000}}},"evaluation":{"attributes":{"correctness":2,"rubric_version":"1.0.0"},"details":{"fact_coverage":[true],"reviewer":"синтетичний-рецензент"}}}
```

## Summary population and denominators

Summaries always include all **nine** configured model/method combinations. `--by-category` emits all nine for every dataset category, including combinations with no attempts. Expected cases are validated dataset questions in the group × frozen repetition count. Missing cases have no saved attempt; failed/incomplete cases have attempts but no success. A case with any success is successful, matching resume semantics.

For measurement/grade averages, select the **earliest successful attempt** per question/model/method/repetition. If there is no success, the latest attempt determines failed/incomplete case status. This rule avoids weighting retries as additional experimental cases or choosing the most favorable answer after grading. Separate successful/failed/incomplete **attempt** counters retain all retry history; `graded_attempts` counts all imported grades and `graded_cases` only grades on the selected observations. A grade on a later retry does not silently replace an ungraded canonical observation.

Performance means use canonical successful cases only. Correctness and coverage means use only manually graded canonical successful cases. Every metric reports `{mean, n, unit}` with an independent non-null denominator; `mean=null, n=0` for no observations. Correctness units are `score_0_to_2`, coverage is a fraction, actual token metrics are tokens, external wall times are milliseconds and Ollama timing metrics are nanoseconds. `facts_covered`/`facts_total` additionally expose pooled fact numerators/denominators. Count fields legitimately use zero; absent measurements never become zero. Means are descriptive, without automatic significance claims.

Different model digests/Ollama versions under one configuration experiment ID are rejected for summary averaging, rather than silently combined; raw-only export remains available for separating campaigns. Unknown model provenance remains explicitly null in the original records. Freeze versions before running a campaign.

Synthetic integration fixture: one question, one repetition, nine successful fake answers, each manually **test-assigned** correctness 2 and coverage `[true]`. All input/output and duration numbers here are HTTP fake values, not real measurements:

| Model | Method | Expected | Successful | Graded | Mean score (n) | Coverage (n) | Input tokens (n) | Output tokens (n) |
| --- | --- | ---: | ---: | ---: | --- | --- | --- | --- |
| qwen2.5-coder:1.5b | baseline | 1 | 1 | 1 | 2 (1) | 1 (1) | 100 (1) | 12 (1) |
| qwen2.5-coder:1.5b | keyword | 1 | 1 | 1 | 2 (1) | 1 (1) | 100 (1) | 12 (1) |
| qwen2.5-coder:1.5b | structure_aware | 1 | 1 | 1 | 2 (1) | 1 (1) | 100 (1) | 12 (1) |
| qwen2.5-coder:7b | baseline | 1 | 1 | 1 | 2 (1) | 1 (1) | 100 (1) | 12 (1) |
| qwen2.5-coder:7b | keyword | 1 | 1 | 1 | 2 (1) | 1 (1) | 100 (1) | 12 (1) |
| qwen2.5-coder:7b | structure_aware | 1 | 1 | 1 | 2 (1) | 1 (1) | 100 (1) | 12 (1) |
| qwen2.5-coder:14b | baseline | 1 | 1 | 1 | 2 (1) | 1 (1) | 100 (1) | 12 (1) |
| qwen2.5-coder:14b | keyword | 1 | 1 | 1 | 2 (1) | 1 (1) | 100 (1) | 12 (1) |
| qwen2.5-coder:14b | structure_aware | 1 | 1 | 1 | 2 (1) | 1 (1) | 100 (1) | 12 (1) |

Each fake inference has `eval_duration=700000000` ns and `total_duration=1000000000` ns. External wall times are genuinely measured during tests and therefore not fixed example numbers. Additional tests cover failed/incomplete retries, ungraded/missing combinations, zero-attempt campaigns, partial fact coverage and missing token metrics. No final dataset, real grades or research results are produced by this phase.
