<?php

namespace App\Http\Controllers\AskCodeAtlas;

use App\AskCodeAtlas\RecoverStaleQuestions;
use App\Http\Controllers\Controller;
use App\Http\Requests\AskCodeAtlas\StoreQuestionRequest;
use App\Jobs\AnswerRepositoryQuestionJob;
use App\Models\Project;
use App\Models\ProjectRevision;
use App\Models\RepositoryQuestion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RepositoryQuestionController extends Controller
{
    public function index(Project $project, RecoverStaleQuestions $recovery): JsonResponse
    {
        $this->authorizeProject($project);
        $user = request()->user();
        assert($user instanceof User);
        $recovery->handle($user->id, $project->id);

        $revisions = ProjectRevision::query()->where('project_id', $project->id)
            ->whereHas('indexRuns', fn ($query) => $query->where('project_id', $project->id)->where('status', 'completed'))
            ->latest('id')->get()->filter(fn (ProjectRevision $revision): bool => $this->fingerprint($revision) !== null)
            ->map(fn (ProjectRevision $revision): array => [
                'id' => $revision->id,
                'identifier' => $revision->identifier,
                'fingerprint' => $this->fingerprint($revision),
            ])->values();

        $questions = RepositoryQuestion::query()->with('revision')->where('user_id', $user->id)->where('project_id', $project->id)
            ->latest('id')->limit(50)->get()->map(fn (RepositoryQuestion $question): array => [
                'id' => $question->public_id,
                'question' => $question->question,
                'model' => $question->requested_model,
                'retrieval_method' => $question->retrieval_method,
                'status' => $question->status,
                'revision' => $question->revision->identifier,
                'created_at' => $this->timestamp($question, 'created_at'),
            ]);

        return response()->json(['project' => $project->only(['id', 'name']), 'revisions' => $revisions, 'questions' => $questions])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(StoreQuestionRequest $request, Project $project): JsonResponse
    {
        $this->authorizeProject($project);
        $user = $request->user();
        assert($user instanceof User);
        $key = (string) $request->validated('idempotency_key');
        $requestHash = hash_hmac('sha256', json_encode([
            'revision_id' => $request->integer('revision_id'),
            'question' => trim((string) $request->validated('question')),
            'model' => $request->validated('model'),
            'retrieval_method' => $request->validated('retrieval_method'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), (string) config('app.key'));
        $existing = RepositoryQuestion::query()->where('user_id', $user->id)->where('project_id', $project->id)
            ->where('idempotency_key', $key)->first();
        if ($existing !== null) {
            abort_unless(hash_equals($existing->request_sha256, $requestHash), Response::HTTP_CONFLICT, 'This idempotency key was already used for a different question.');

            return response()->json($this->summary($existing), Response::HTTP_ACCEPTED)
                ->header('Cache-Control', 'private, no-store');
        }

        $revision = ProjectRevision::query()->where('project_id', $project->id)
            ->whereKey($request->integer('revision_id'))->whereHas('indexRuns', fn ($query) => $query->where('project_id', $project->id)->where('status', 'completed'))->firstOrFail();
        $fingerprint = $this->fingerprint($revision);
        abort_if($fingerprint === null, Response::HTTP_UNPROCESSABLE_ENTITY, 'The selected revision has no verified immutable snapshot.');
        $metadata = json_decode((string) $revision->getRawOriginal('metadata'), true);
        $snapshot = is_array($metadata) ? ($metadata['snapshot'] ?? []) : [];
        $expectedSnapshot = 'projects/'.$project->id.'/revisions/'.$revision->id;
        abort_unless(($snapshot['path'] ?? null) === $expectedSnapshot
            && Storage::disk('repository_snapshots')->directoryExists($expectedSnapshot),
            Response::HTTP_UNPROCESSABLE_ENTITY, 'The selected repository snapshot is unavailable.');
        $questionText = trim((string) $request->validated('question'));
        abort_if($questionText === '', Response::HTTP_UNPROCESSABLE_ENTITY, 'The question must contain non-whitespace text.');

        try {
            $question = DB::transaction(fn (): RepositoryQuestion => RepositoryQuestion::query()->create([
                'public_id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'project_id' => $project->id,
                'project_revision_id' => $revision->id,
                'revision_fingerprint' => $fingerprint,
                'question' => $questionText,
                'requested_model' => (string) $request->validated('model'),
                'retrieval_method' => (string) $request->validated('retrieval_method'),
                'status' => 'queued',
                'idempotency_key' => $key,
                'request_sha256' => $requestHash,
                'queued_at' => now(),
                'heartbeat_at' => now(),
            ]));
        } catch (QueryException $exception) {
            $existing = RepositoryQuestion::query()->where('user_id', $user->id)->where('project_id', $project->id)
                ->where('idempotency_key', $key)->first();
            if ($existing === null) {
                throw $exception;
            }
            abort_unless(hash_equals($existing->request_sha256, $requestHash), Response::HTTP_CONFLICT, 'This idempotency key was already used for a different question.');
            $question = $existing;
        }

        if ($question->status === 'queued' && $question->wasRecentlyCreated) {
            AnswerRepositoryQuestionJob::dispatch($question->id)->onConnection('redis')->onQueue('ask')->afterCommit();
        }

        return response()->json($this->summary($question), Response::HTTP_ACCEPTED)
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(Project $project, RepositoryQuestion $question, RecoverStaleQuestions $recovery): JsonResponse
    {
        $this->authorizeProject($project);
        $user = request()->user();
        assert($user instanceof User);
        abort_unless((int) $question->user_id === (int) $user->id && (int) $question->project_id === (int) $project->id, 404);
        $recovery->handle($user->id, $project->id);
        $question->refresh();

        $context = $question->source_context;
        $chunks = $question->source_chunks;

        return response()->json([
            ...$this->summary($question),
            'answer' => $question->answer,
            'sources' => is_array($context) ? $context : [],
            'selected_chunks' => is_array($chunks) ? $chunks : [],
            'metrics' => [
                'estimated_prompt_tokens' => $question->estimated_prompt_tokens,
                'actual_input_tokens' => $question->actual_input_tokens,
                'actual_output_tokens' => $question->actual_output_tokens,
                'inference_duration_ns' => $question->inference_duration_ns,
                'inference_wall_ms' => $question->inference_wall_ms,
            ],
            'completion' => ['status' => $question->completion_status, 'stop_reason' => $question->stop_reason],
            'safe_error' => $question->safe_error,
        ])->header('Cache-Control', 'private, no-store');
    }

    /** @return array<string, mixed> */
    private function summary(RepositoryQuestion $question): array
    {
        return [
            'id' => $question->public_id,
            'question' => $question->question,
            'model' => $question->requested_model,
            'retrieval_method' => $question->retrieval_method,
            'revision' => $question->revision->identifier,
            'revision_fingerprint' => $question->revision_fingerprint,
            'status' => $question->status,
            'created_at' => $this->timestamp($question, 'created_at'),
            'started_at' => $this->timestamp($question, 'started_at'),
            'completed_at' => $this->timestamp($question, 'completed_at'),
        ];
    }

    private function fingerprint(ProjectRevision $revision): ?string
    {
        $metadata = json_decode((string) $revision->getRawOriginal('metadata'), true);
        $snapshot = is_array($metadata) ? ($metadata['snapshot'] ?? null) : null;
        $fingerprint = is_array($snapshot) ? ($snapshot['fingerprint'] ?? null) : null;

        return is_string($fingerprint) && preg_match('/\A[a-f0-9]{64}\z/', $fingerprint)
            && $revision->identifier === 'snapshot:sha256:'.$fingerprint
            && ($snapshot['disk'] ?? null) === 'repository_snapshots'
            && ($snapshot['path'] ?? null) === 'projects/'.$revision->project_id.'/revisions/'.$revision->id
            ? $fingerprint
            : null;
    }

    private function authorizeProject(Project $project): void
    {
        Gate::authorize('view', $project);
    }

    private function timestamp(RepositoryQuestion $question, string $field): ?string
    {
        $value = $question->getAttribute($field);

        return $value instanceof \DateTimeInterface ? Carbon::instance($value)->toIso8601String() : null;
    }
}
