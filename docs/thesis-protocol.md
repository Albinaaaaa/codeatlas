# Thesis protocol 1.0.0

Research: **Research on Methods for Context Construction and Optimization in Large Language Models for Source Code Analysis.**

Experiment completion deadline: **2026-11-12**. Phase 1 defines the protocol and checks model inventory. It does not implement retrieval, inference, evaluation, or an experiment runner.

## Configuration

`config/thesis.php` is the versioned source of truth; future commands should read `config('thesis')`. The full matrix is the Cartesian product of:

| Model | Methods |
| --- | --- |
| `qwen2.5-coder:1.5b` | `baseline`, `keyword`, `structure_aware` |
| `qwen2.5-coder:7b` | `baseline`, `keyword`, `structure_aware` |
| `qwen2.5-coder:14b` | `baseline`, `keyword`, `structure_aware` |

Method identifiers are reserved; their algorithms and deterministic tie-breaking rules must be specified and versioned before experiments. No retrieval algorithm is implemented in this phase.

| Environment variable | Default | Meaning |
| --- | --- | --- |
| `OLLAMA_URL` | `http://127.0.0.1:11434` | Existing Ollama endpoint; Compose overrides it to `http://ollama:11434` |
| `THESIS_CONTEXT_TOKEN_BUDGET` | 4096 | Maximum retrieved source-context tokens, excluding question and prompt overhead |
| `THESIS_MAX_OUTPUT_TOKENS` | 512 | Planned Ollama `num_predict` |
| `THESIS_CONTEXT_WINDOW_TOKENS` | 8192 | Planned Ollama `num_ctx`, including all prompt and output tokens |
| `THESIS_REPETITIONS` | 3 | Measured repetitions of each question/model/method combination |

All integer parameters must be positive. The complete rendered prompt, including chat-template overhead, plus the output allowance must fit the context window. Context budget plus output limit alone is not sufficient validation. Future execution must count actual model tokens and reject overflowing inputs, not silently truncate them. Use the same source budget, prompt template, output limit and generation settings for all nine combinations. Tokenizers may differ; retain actual prompt and output counts per model.

The exact prompt template and version are in the configuration. Substitute `{{context}}` and `{{question}}` once as literal values; do not recursively interpret repository content as template syntax. Generation defaults are temperature 0, seed 42, top-k 40, top-p 0.9 and repeat penalty 1.1. Changes are made in the versioned configuration. These are initial experimental choices, not validated optimal settings. A seed does not guarantee identical results across runtimes/hardware.

## Explicitly incomplete dataset

`status = incomplete`, null dataset ID/version/hash and an empty repository list are intentional. They are not sample results or permission to run experiments. Before marking the protocol ready, supply:

- Dataset ID, version and SHA-256 of the frozen question/reference artifact.
- For every repository: `id`, `source_type`, `revision_id` (the existing immutable CodeAtlas snapshot/revision identifier), and `content_sha256` of its frozen manifest. Record a commit SHA where applicable, but do not substitute a branch name for an immutable revision.
- Stable question IDs mapped to repository revisions, reference-answer criteria and gold evidence locations in those revisions. Human-review and freeze these before scoring model output.
- Versioned method specifications, chunking/tokenization rules and evidence matching rules.

No repository revision, gold answer or dataset identity has been invented. Future runners must reject incomplete datasets and invalid numeric settings. Preflight success means only that required model inventory/metadata checks passed; it does not mark the dataset ready.

## Measurement rules for the future runner

Freeze the protocol, effective configuration, dataset checksum, repository snapshot hashes, application Git revision, Ollama version, model digests/quantization, hardware and runtime limits for each experiment campaign. Tags and the Compose `latest` image are mutable: record their resolved identities. Change the protocol version when prompt, method, scoring or experimental defaults change; retain old configurations with results.

Run every frozen question in all nine combinations. Use three measured repetitions with the same configured seed and report each observation plus mean and standard deviation; repetitions measure timing/runtime variability, not three independent quality samples. Do not count repeated identical answers as independent questions. Use a recorded fixed randomized ordering across combinations to limit order effects. Use one unmeasured warm-up per model with the same context window, exclude it from measurements, and record model load time separately. Execute sequentially with other inference workloads stopped manually. Record failures/timeouts rather than silently dropping them.

Required metrics:

| Identifier | Definition |
| --- | --- |
| `answer_accuracy` | Per-question fraction of frozen reference criteria satisfied, scored without model/method labels; aggregate over questions. Document adjudication and unanswerable-question criteria before collecting results. |
| `retrieval_precision` | Relevant retrieved evidence units / all retrieved units. Gold units and overlap/deduplication rules must be frozen with the dataset. |
| `retrieval_recall` | Retrieved gold evidence units / all gold evidence units. |
| `prompt_tokens` | Actual Ollama `prompt_eval_count`, including prompt overhead; never a character-count estimate. |
| `output_tokens` | Actual Ollama `eval_count`. |
| `context_construction_ms` | Monotonic elapsed time to construct context from the prepared revision. |
| `inference_ms` | Monotonic client elapsed time for the inference request, including transport. |
| `total_ms` | Monotonic elapsed time from context construction start to complete answer; one-time repository preparation excluded. |

Precision is 0 for empty retrieval when gold evidence exists. Questions with no gold evidence have undefined precision/recall: report null and exclude them from retrieval averages with explicit counts. Report accuracy and retrieval quality by question and combination; preserve denominators and failure counts. Keep optional Ollama timing fields in their native nanoseconds or convert explicitly. Missing measurements are null, never fabricated zeroes. No measurements or exports are produced in this phase.

## Preflight

```sh
php artisan thesis:preflight
# When using the existing Compose network:
docker compose exec app php artisan thesis:preflight
```

The command reads `services.ollama.url` from the existing `OLLAMA_URL`. It calls only `GET /api/tags` and `POST /api/show`, with a 3-second connection timeout and a 10-second request timeout, no retries or redirects. It reports exact required tags, digests, parameter sizes, quantization, declared model context limits and model-level `num_ctx` if present. The declared architecture limit is not proof of active server context allocation or available memory. Unreported limits/defaults remain unknown. API definitions: [official Ollama API documentation](https://github.com/ollama/ollama/blob/main/docs/api.md).

Exit code 0: all required models present, metadata structurally valid, and no known context-limit conflict. Exit code 1: invalid endpoint, connection/HTTP failure, invalid response, missing model, failed metadata inspection or known context-limit conflict. Unknown metadata fields are printed as unknown. It never pulls models, runs inference, writes project data or writes reports. Inference is verified separately through the Ollama API; no `--smoke` feature is included.

## Phase 01 completion

Phase 01 (Research Preflight) is complete. The operator confirmed the following runtime results, superseding the earlier Docker/network failures observed on 2026-10-23:

- Laravel successfully connects to PostgreSQL 18.6.
- The CodeAtlas database contains 37 tables.
- Docker and Ollama are operational.
- All three required models are installed and successfully generated responses through the Ollama API.

| Model | Initial inference duration, including model loading |
| --- | --- |
| `qwen2.5-coder:1.5b` | 7.0 s |
| `qwen2.5-coder:7b` | 27.6 s |
| `qwen2.5-coder:14b` | 47.6 s |

These are manual operational checks, not controlled experiment results or warm inference benchmarks. They do not establish accuracy, retrieval quality, memory use, GPU acceleration, or effective context allocation. Model digests and runtime settings still need to be captured for experiment campaigns.

Phase completion does not change the protocol's intentionally incomplete dataset status. Dataset preparation, retrieval, evaluation, and experiment execution belong to later phases.

## Focused tests

```sh
docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=codeatlas_test app php vendor/bin/phpunit tests/Unit/ThesisPreflightTest.php
docker compose exec -T app php vendor/bin/pint --test app/Console/Commands/ThesisPreflightCommand.php config/thesis.php config/services.php tests/Unit/ThesisPreflightTest.php
docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=codeatlas_test app php -d memory_limit=1G vendor/bin/phpstan analyse app/Console/Commands/ThesisPreflightCommand.php --no-progress
```

The tests use Laravel's base testing class with database connections removed immediately after configuration loading, before providers bootstrap. They deliberately do not use the project test base that connects to PostgreSQL. All HTTP calls are faked and stray HTTP is prohibited. No migrations or development-database queries are executed.

Next milestone: prepare repositories through existing `ProjectSource` and immutable revision abstractions, then define deterministic code chunking with stable IDs, path/line provenance, consistent exclusions and focused fixtures. Freeze those rules before implementing the three context methods. This work is not part of Phase 1.
