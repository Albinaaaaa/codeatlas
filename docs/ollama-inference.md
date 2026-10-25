# Ollama inference — Phase 06

Actual CLI signature:

```text
thesis:ask {revisionId} {question} {--method=keyword} {--model=}
```

The default model is `thesis.models.0`, currently `qwen2.5-coder:1.5b`. Only the three configured research models (`qwen2.5-coder:1.5b`, `qwen2.5-coder:7b`, `qwen2.5-coder:14b`) are supported. Empty/unknown model identifiers, missing revisions/chunks, invalid methods and empty questions fail explicitly. Existing revisions and generated chunks are required; the command never scans, indexes, rebuilds chunks, downloads models or persists experiments.

```sh
php artisan thesis:ask 123 "What does answer return?" --method=baseline --model=qwen2.5-coder:1.5b
php artisan thesis:ask 123 "What does answer return?" --method=keyword --model=qwen2.5-coder:7b
php artisan thesis:ask 123 "What does answer return?" --method=structure_aware --model=qwen2.5-coder:14b
```

`OllamaInference` uses the existing `BuildContext` and sends its exact prompt to the existing `services.ollama.url`. It calls `POST /api/show` for the requested model, then `POST /api/generate` with `stream: false` and `raw: false`. Ollama applies the installed Qwen model template once. The inspected template's ordinary text-generation branch inserts the supplied prompt once as user content; CodeAtlas does not pre-wrap it in ChatML, override the template, or send a second system copy of the research instructions. The installed model's default system message is retained. Template and model-system SHA-256 values are captured from model metadata, without echoing their text. The original Phase 05 prompt, template version and SHA-256 remain unchanged; this hash identifies the supplied prompt, not the server-formatted token sequence. The Phase 05 estimator and chat-template allowance are unchanged. See the [official generate API](https://docs.ollama.com/api/generate) and [Ollama API reference](https://github.com/ollama/ollama/blob/main/docs/api.md).

Generation options come from `thesis.generation`: temperature, seed, top-k, top-p and repeat penalty. `num_ctx` and `num_predict` use the exact context-window and output limits validated by Context Builder. They are identical across retrieval methods under the same effective configuration. No automatic context reduction, output-limit change or model substitution occurs. A returned model identifier different from the requested identifier fails.

`thesis.inference.timeout_seconds` defaults to 300 and can be set with `THESIS_OLLAMA_TIMEOUT` before rebuilding Laravel's config cache if one is in use. Connection timeout defaults to 3 seconds. Model inspection uses at most 10 seconds; generation uses the full configured timeout. For a slow CPU/14B setup, explicitly configure a larger timeout such as 600. Timeouts do not imply the server has cancelled queued/computing work. There are no retries or redirects.

Known positive architecture `*.context_length` limits are checked against `num_ctx`; if multiple are reported, use the smallest. A known conflict fails before generation. If no limit is reported, it remains `null` with status `unknown`, never an invented model capability. Advertised capabilities must include `completion` when present. These checks do not prove available RAM, acceleration or effective server allocation. Model load/resource errors remain failures; no smaller model is substituted.

## Result and completion semantics

The JSON result contains requested/returned models, revision ID/identifier, method, question, unchanged prompt/hash, answer, selected/rejected chunks, effective options/limits and request flags. Output is written raw so Symfony cannot strip XML-like tags from prompts or answers. It is private command stdout, not public storage or application logs.

- `actual_metrics` preserves Ollama's non-negative integer `prompt_eval_count`, `eval_count`, `total_duration`, `load_duration`, `prompt_eval_duration` and `eval_duration` fields. Durations retain their original **nanoseconds**; `duration_unit` names that unit. Missing/null metrics are null and listed in `metrics_unavailable`. Invalid present numeric types or negative metrics fail rather than being coerced.
- `timings_ms` uses the external monotonic clock: Context Builder execution, the generation HTTP request, and the whole workflow (including model inspection). These wall times include different work from server duration fields and are not interchangeable.
- `estimated_token_usage` is the unchanged Phase 05 estimate. `token_comparison` reports its prompt estimate, actual prompt count and estimated-minus-actual difference. It also checks actual prompt tokens plus the requested output reserve against requested `num_ctx`; unknown actual counts produce null. This is not proof that the server never truncated input or silently adjusted allocation.
- `done` must be true, the returned model must match and the answer must be non-empty. `done_reason = stop` is `complete`; `length` is `truncated`. Missing or unrecognized reasons are `unknown`, never assumed complete. Partial `done: false` responses fail.

Exit codes: **0** for confirmed normal completion without a known actual-token budget conflict; **1** for validation, transport, HTTP, metadata or response failure; **2** for a returned truncated/unknown-completion answer or a known actual-token budget conflict. Exit code 2 still returns the answer and metrics with explicit status. Missing metrics alone are not fabricated or treated as zero.

Errors do not echo server response bodies, transport messages, endpoint credentials or private prompts. Inference failures do not retain transport exception chains and implement Laravel's `ShouldntReport`; no logging is added. Callers must also avoid logging successful results, which intentionally contain source prompts. No experiment schema, runner, evaluation, exports, UI or conversation history is implemented.

## Automated checks

Use the verified isolated testing connection, never the development database:

```sh
docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=codeatlas_test -e DB_URL= app php vendor/bin/phpunit tests/Feature/Inference/OllamaInferenceTest.php tests/Feature/RepositoryAnalysis/ContextBuilderTest.php tests/Feature/RepositoryAnalysis/CodeRetrievalTest.php
```

Inference tests fake all HTTP calls and prohibit stray requests. Real inference is a separate manual check on a tiny indexed synthetic repository; it is not part of the PHPUnit suite and is not an experiment result.

## Verified local smoke checks

Ollama **0.33.1**, isolated `codeatlas_test`, synthetic immutable `answer.php` snapshot with `function answer(): int { return 42; }`. The ordinary inventory, PHP analysis and chunk builder ran before asking the question. All final requests used `keyword`, question `What integer does answer() return? Reply with just the integer.`, temperature 0, seed 42, top-k 40, top-p 0.9, repeat penalty 1.1, `num_ctx=2048`, `num_predict=96`, timeout 600 seconds, `raw=false` and `stream=false`. These small-check overrides were process-local; `.env` and research defaults were not changed.

| Model | Answer | CLI / stop reason | Estimated / actual prompt tokens | Output tokens | Server total (ns) | Server load (ns) | HTTP wall (ms) |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `qwen2.5-coder:1.5b` | `42` | 0 / stop | 926 / 186 | 3 | 12910808775 | 17860512 | 12928.427 |
| `qwen2.5-coder:7b` | `42` | 0 / stop | 926 / 186 | 3 | 33939390645 | 15248714 | 33966.355 |
| `qwen2.5-coder:14b` | `42` | 0 / stop | 926 / 186 | 3 | 40614314363 | 4008982 | 40627.111 |

All required metrics were present, requested and returned model identifiers matched, and the actual prompt plus output reserve fit the requested context window. All requests shared prompt SHA-256 `59b9fd857e3d9cf58181bea1bbb441632e795324f702efd7ca05e5c9ff7221ac`. The installed templates had SHA-256 `1e65450c30670713aa47fe23e8b9662bdf4065e81cc8e3cbfaa98924fcc0d320`; their default system messages had SHA-256 `66b9ea09bd5b7099cbb4fc820f31b575c0366fa439b08245566692c6784e281e`.

A preliminary raw-mode probe made the 1.5B model repeat input and hit its output limit, correctly producing a truncated status and exit code 2. The final integration therefore uses the installed instruction-model template, not raw completion or an application-defined wrapper. The final models were already resident from earlier probes, so the timings above are not cold-load benchmarks or controlled experiment measurements. Real capacity at the research defaults `num_ctx=8192` / `num_predict=512` was not established by these short checks. The synthetic transaction was rolled back and its private snapshot removed; no answers were persisted.

Abbreviated actual 1.5B result (the full response also includes unchanged prompt, selected chunk stable IDs/ranges, options, limits and model metadata):

```json
{
  "requested_model": "qwen2.5-coder:1.5b",
  "returned_model": "qwen2.5-coder:1.5b",
  "revision_identifier": "phase06-synthetic-smoke",
  "method": "keyword",
  "question": "What integer does answer() return? Reply with just the integer.",
  "prompt_hash": "59b9fd857e3d9cf58181bea1bbb441632e795324f702efd7ca05e5c9ff7221ac",
  "answer": "42",
  "actual_metrics": {
    "prompt_eval_count": 186,
    "eval_count": 3,
    "total_duration": 12910808775,
    "load_duration": 17860512,
    "prompt_eval_duration": 11728844000,
    "eval_duration": 280057000
  },
  "metrics_unavailable": [],
  "duration_unit": "nanoseconds",
  "timings_ms": {
    "context_construction": 13.611,
    "inference_wall": 12928.427,
    "total_wall": 12949.759
  },
  "token_comparison": {
    "estimated_prompt_tokens": 926,
    "actual_prompt_tokens": 186,
    "estimated_minus_actual": 740,
    "actual_prompt_plus_output_reserve_fits_num_ctx": true
  },
  "completion": { "status": "complete", "done": true, "stop_reason": "stop" }
}
```
