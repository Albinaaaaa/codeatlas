# Retrieval v1

All three strategies implement `RetrievalStrategy::retrieve(int $revisionId, string $question)` and read persisted chunks only. They never scan, rebuild chunks, open snapshots or execute repository code. The caller supplies an explicit revision; every data query is scoped to that revision and its project. Empty questions, unknown revisions and revisions without chunks fail explicitly. A valid question without lexical matches returns an empty keyword/structure-aware list.

Candidates contain the chunk's stable ID, revision database ID and immutable identifier, relative path, inclusive line range, one-based rank, nullable score and selection reason. Database symbol IDs are used only to join the current index; they never appear in permanent references or reasons. Older chunks without a stable ID use SHA-256 of a version marker, path, range and content hash. Duplicate stable IDs are returned once.

Baseline returns every chunk ordered by path (bytewise), start line and stable ID. It ignores question text and uses a null score.

Keyword splits acronym/camelCase boundaries, lowercases with UTF-8 handling and splits on non-letter/non-digit characters (including underscores, PHP namespace separators and punctuation). Terms are unique; there is no stemming, stop-word list or substring matching. For each distinct question term, add 1 if present in content, 3 if present in the symbol name, and 2 if present in the relative path. Each field contributes once regardless of term frequency. The symbol-name field combines chunk metadata with its indexed symbol's qualified name. Return positive scores, descending; ties use Baseline order. This deliberately simple scoring is not BM25 or semantic similarity.

Structure-aware uses the first 5 keyword results as seeds, retaining their order and scores. It then appends at most 20 previously unseen candidates through a deterministic breadth-first traversal, at depth at most 2. Edges are bidirectional: a question about either endpoint may need the other endpoint. At each depth, process the previous frontier in selection order, and each node's neighbors in Baseline order; if multiple edge types join the same pair, the first bytewise-sorted type labels the reason. Expansion scores are null; reasons record edge type, preceding stable chunk ID and depth. No seeds means no expansion.

Supported edges:

- Indexed methods and their indexed parent class/interface/trait/enum, excluding namespace containment.
- Resolved `extends`, `implements` and `trait_use` rows in `code_relations`; unresolved target names are never guessed.
- Laravel model relations with resolved source and target model records and chunk-backed symbols.
- Laravel route chunks overlapping indexed route line ranges and their resolved controller method (validated against its controller parent), or the resolved controller class when a method ID is unavailable.

Both endpoints must have generated chunks. Standalone functions without persisted symbol rows can participate in lexical retrieval but not graph traversal. One-line constructs may share one chunk. There is no call graph, arbitrary reference resolution, inferred dispatch/listener graph, dynamic route evaluation or expansion to unresolved model targets (including dynamic polymorphic targets). Additional edges must be separately specified and tested before changing this algorithm for experiments.

```sh
php artisan thesis:retrieve 123 "load invoice" --method=baseline
php artisan thesis:retrieve 123 "load invoice" --method=keyword
php artisan thesis:retrieve 123 "load invoice" --method=structure_aware
```
