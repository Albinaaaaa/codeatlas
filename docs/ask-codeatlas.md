# Ask CodeAtlas backend

Ask CodeAtlas stores one private question per request and runs inference in the `ask` Horizon queue. Questions, answers, and selected source fragments are encrypted in the database. The API returns only relative source paths, line ranges, retrieval metadata, and actual metrics; it never returns the internal prompt.

## Inference scheduling

`OllamaInference::ask` takes the shared `codeatlas:ollama-inference` cache lock before contacting Ollama. Research runs and the existing CLI command call the same service, so they cannot generate in parallel with Ask CodeAtlas. Context preparation occurs before the lock, and lock wait is removed from measured wall time. Research can wait up to the Ollama timeout plus a safety margin for an active Ask generation; the Ask queue waits 15 seconds per attempt by default (`OLLAMA_INTERACTIVE_LOCK_WAIT_SECONDS`) and retries contention at most eight times. The lock expires after the configured Ollama timeout plus a safety margin. `OLLAMA_LOCK_STORE` selects the shared cache store and defaults to `CACHE_STORE` (or Redis when unset); all web and queue workers must use the same shared store.

The Ask worker is limited to one Horizon process. It waits for an active inference for a bounded period, retries lock contention a bounded number of times, then records an incomplete state rather than submitting a duplicate generation. Research remains on its existing single-process `research` queue.

## Local operation

1. Apply the new database migration with `php artisan migrate` after checking the intended database.
2. Configure Redis and set `CACHE_STORE=redis` or `OLLAMA_LOCK_STORE=redis` so HTTP, Horizon, and CLI processes share the inference lock.
3. Start or reload Horizon with `php artisan horizon` / `php artisan horizon:terminate`; check it with `php artisan horizon:status`.
4. Ensure the `supervisor-ask` and existing `supervisor-research` supervisors are enabled in the active Horizon environment.

Stale `queued`, `preparing`, or `running` records are marked incomplete when their owner opens history or a detail URL. An interrupted generation is never replayed automatically; check the worker and submit a fresh question to retry it.
