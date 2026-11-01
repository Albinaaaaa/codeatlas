import { Head, Link } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslations } from '@/hooks/use-translations';
import type { ResearchProject } from '@/types/research';

type Revision = { id: number; identifier: string; fingerprint: string };
type HistoryQuestion = {
    id: string;
    question: string;
    model: string;
    retrieval_method: string;
    status: Status;
    revision: string;
    created_at: string | null;
};
type Status =
    'queued' | 'preparing' | 'running' | 'completed' | 'incomplete' | 'failed';
type Detail = HistoryQuestion & {
    revision_fingerprint: string;
    started_at: string | null;
    completed_at: string | null;
    answer: string | null;
    sources: { source: string; content: string }[];
    selected_chunks: {
        path: string;
        start_line: number;
        end_line: number;
        chunk_id: string;
        selection_reason: string | null;
    }[];
    metrics: {
        estimated_prompt_tokens: number | null;
        actual_input_tokens: number | null;
        actual_output_tokens: number | null;
        inference_duration_ns: number | null;
        inference_wall_ms: number | null;
    };
    completion: { status: string | null; stop_reason: string | null };
    safe_error: string | null;
};
type Props = {
    project: ResearchProject;
    configuration: { models: string[]; methods: string[] };
    endpoints: { history: string; store: string; detail: string };
};
type HistoryResponse = { revisions: Revision[]; questions: HistoryQuestion[] };

const terminalStatuses: Status[] = ['completed', 'incomplete', 'failed'];

async function readJson<T>(response: Response): Promise<T> {
    const body = await response.json().catch(() => ({}));

    if (!response.ok) {
        const error = new Error(
            body.message || `HTTP ${response.status}`,
        ) as Error & {
            status?: number;
            errors?: Record<string, string[]>;
        };
        error.status = response.status;
        error.errors = body.errors;

        throw error;
    }

    return body as T;
}

function detailUrl(template: string, id: string): string {
    return template.replace('__QUESTION__', encodeURIComponent(id));
}

export default function AskIndex({ project, configuration, endpoints }: Props) {
    const { t } = useTranslations();
    const [revisions, setRevisions] = useState<Revision[]>([]);
    const [history, setHistory] = useState<HistoryQuestion[]>([]);
    const [revisionId, setRevisionId] = useState('');
    const [model, setModel] = useState(configuration.models[0] ?? '');
    const [method, setMethod] = useState(configuration.methods[0] ?? '');
    const [question, setQuestion] = useState('');
    const [detail, setDetail] = useState<Detail | null>(null);
    const [loadingHistory, setLoadingHistory] = useState(true);
    const [loadingDetail, setLoadingDetail] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState('');
    const [questionError, setQuestionError] = useState('');
    const [retryAvailable, setRetryAvailable] = useState(false);
    const keyRef = useRef<string | null>(null);
    const payloadRef = useRef<{
        revision_id: number;
        question: string;
        model: string;
        retrieval_method: string;
    } | null>(null);
    const mountedRef = useRef(true);

    const loadHistory = useCallback(
        async (signal?: AbortSignal) => {
            try {
                const response = await fetch(endpoints.history, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    signal,
                });
                const data = await readJson<HistoryResponse>(response);

                if (!signal?.aborted) {
                    setRevisions(data.revisions);
                    setHistory(data.questions);
                    setRevisionId(
                        (current) =>
                            current || String(data.revisions[0]?.id ?? ''),
                    );
                    const requestedId = new URLSearchParams(
                        window.location.search,
                    ).get('question');
                    const restored = data.questions.find(
                        (item) => item.id === requestedId,
                    );
                    const restoredRevision = data.revisions.find(
                        (item) => item.identifier === restored?.revision,
                    );

                    if (restoredRevision) {
                        setRevisionId(String(restoredRevision.id));
                    }
                }
            } catch (cause) {
                if (!signal?.aborted) {
                    setError(
                        cause instanceof Error ? cause.message : t('ask.error'),
                    );
                }
            } finally {
                if (!signal?.aborted) {
                    setLoadingHistory(false);
                }
            }
        },
        [endpoints.history, t],
    );

    const loadDetail = useCallback(
        async (id: string, signal?: AbortSignal) => {
            setLoadingDetail(true);
            setError('');

            try {
                const response = await fetch(detailUrl(endpoints.detail, id), {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    signal,
                    cache: 'no-store',
                });
                const data = await readJson<Detail>(response);

                if (!signal?.aborted) {
                    setDetail(data);
                }
            } catch (cause) {
                if (!signal?.aborted) {
                    setError(
                        cause instanceof Error ? cause.message : t('ask.error'),
                    );
                }
            } finally {
                if (!signal?.aborted) {
                    setLoadingDetail(false);
                }
            }
        },
        [endpoints.detail, t],
    );

    useEffect(() => {
        mountedRef.current = true;
        const controller = new AbortController();
        const initialLoad = window.setTimeout(() => {
            void loadHistory(controller.signal);
            const initialId = new URLSearchParams(window.location.search).get(
                'question',
            );

            if (initialId) {
                void loadDetail(initialId, controller.signal);
            }
        }, 0);

        return () => {
            mountedRef.current = false;
            window.clearTimeout(initialLoad);
            controller.abort();
        };
    }, [loadDetail, loadHistory]);

    const activeDetailId = detail?.id;
    const activeDetailStatus = detail?.status;

    useEffect(() => {
        if (
            !activeDetailId ||
            !activeDetailStatus ||
            terminalStatuses.includes(activeDetailStatus)
        ) {
            return;
        }

        let stopped = false;
        let timer: ReturnType<typeof setTimeout>;
        let controller: AbortController | null = null;
        const poll = async () => {
            controller = new AbortController();

            try {
                const response = await fetch(
                    detailUrl(endpoints.detail, activeDetailId),
                    {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                        cache: 'no-store',
                        signal: controller.signal,
                    },
                );
                const updated = await readJson<Detail>(response);

                if (!stopped) {
                    setDetail(updated);
                    setHistory((items) =>
                        items.map((item) =>
                            item.id === updated.id
                                ? { ...item, status: updated.status }
                                : item,
                        ),
                    );
                }
            } catch {
                // The next poll retries transient network failures without overlapping requests.
            }

            if (!stopped) {
                timer = setTimeout(poll, 2500);
            }
        };
        timer = setTimeout(poll, 1500);

        return () => {
            stopped = true;
            clearTimeout(timer);
            controller?.abort();
        };
    }, [activeDetailId, activeDetailStatus, endpoints.detail]);

    const openQuestion = (id: string) => {
        setDetail(null);
        const savedQuestion = history.find((item) => item.id === id);
        const savedRevision = revisions.find(
            (item) => item.identifier === savedQuestion?.revision,
        );

        if (savedRevision) {
            setRevisionId(String(savedRevision.id));
        }

        const url = new URL(window.location.href);
        url.searchParams.set('question', id);
        window.history.replaceState(window.history.state, '', url);
        void loadDetail(id);
    };

    const submit = async (retry = false) => {
        if (submitting) {
            return;
        }

        setError('');
        setQuestionError('');
        let payload = payloadRef.current;

        if (!retry || !payload || !keyRef.current) {
            if (!revisionId) {
                setError(t('ask.choose_revision'));

                return;
            }

            if (!question.trim()) {
                setQuestionError(t('ask.question_required'));

                return;
            }

            payload = {
                revision_id: Number(revisionId),
                question: question.trim(),
                model,
                retrieval_method: method,
            };
            payloadRef.current = payload;
            keyRef.current = crypto.randomUUID();
        }

        setSubmitting(true);
        setRetryAvailable(false);

        try {
            const token = document.querySelector<HTMLMetaElement>(
                'meta[name="csrf-token"]',
            )?.content;
            const response = await fetch(endpoints.store, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    ...(token ? { 'X-CSRF-TOKEN': token } : {}),
                },
                body: JSON.stringify({
                    ...payload,
                    idempotency_key: keyRef.current,
                }),
            });
            const accepted = await readJson<HistoryQuestion>(response);

            if (response.status !== 202) {
                throw new Error(t('ask.unexpected_response'));
            }

            keyRef.current = null;
            payloadRef.current = null;
            setQuestion('');
            setDetail(null);
            const url = new URL(window.location.href);
            url.searchParams.set('question', accepted.id);
            window.history.replaceState(window.history.state, '', url);
            setHistory((items) => [
                accepted,
                ...items.filter((item) => item.id !== accepted.id),
            ]);
            await loadDetail(accepted.id);
        } catch (cause) {
            const failure = cause as Error & {
                status?: number;
                errors?: Record<string, string[]>;
            };

            if (failure.status === 422 && failure.errors?.question?.[0]) {
                setQuestionError(failure.errors.question[0]);
                keyRef.current = null;
                payloadRef.current = null;
            } else {
                setError(failure.message || t('ask.error'));
                setRetryAvailable(
                    Boolean(keyRef.current && payloadRef.current),
                );
            }
        } finally {
            if (mountedRef.current) {
                setSubmitting(false);
            }
        }
    };

    const statusLabel = (status: Status) => t(`ask.status.${status}`);
    const seconds =
        detail?.metrics.inference_duration_ns == null
            ? null
            : (detail.metrics.inference_duration_ns / 1e9).toFixed(2);

    return (
        <>
            <Head title={`${t('ask.title')} · ${project.name}`} />
            <main className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="flex min-w-0 flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 space-y-2">
                        <h1 className="text-2xl font-semibold tracking-tight break-words">
                            {t('ask.title')}
                        </h1>
                        <p className="max-w-3xl text-sm text-muted-foreground">
                            {t('ask.description', { project: project.name })}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            {t('ask.help')}
                        </p>
                    </div>
                    <Button variant="outline" asChild>
                        <Link href={`/projects/${project.id}`}>
                            {t('ask.back_to_project')}
                        </Link>
                    </Button>
                </header>
                {error && (
                    <div
                        role="alert"
                        className="rounded-md border border-destructive/40 bg-destructive/5 p-3 text-sm break-words"
                    >
                        {error}
                    </div>
                )}
                <div className="grid min-w-0 gap-6 xl:grid-cols-[minmax(18rem,0.8fr)_minmax(0,1.6fr)]">
                    <div className="min-w-0 space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>{t('ask.new_question')}</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {revisions.length === 0 && !loadingHistory ? (
                                    <div className="space-y-3 rounded-md border p-4 text-sm">
                                        <p>{t('ask.no_revisions')}</p>
                                        <Button variant="outline" asChild>
                                            <Link
                                                href={`/projects/${project.id}`}
                                            >
                                                {t('ask.open_indexing')}
                                            </Link>
                                        </Button>
                                    </div>
                                ) : (
                                    <>
                                        <div className="space-y-2">
                                            <Label htmlFor="revision">
                                                {t('ask.revision')}
                                            </Label>
                                            <select
                                                id="revision"
                                                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm ring-offset-background outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                                value={revisionId}
                                                onChange={(event) =>
                                                    setRevisionId(
                                                        event.target.value,
                                                    )
                                                }
                                                disabled={
                                                    submitting || retryAvailable
                                                }
                                            >
                                                <option value="">
                                                    {t('ask.select_revision')}
                                                </option>
                                                {revisions.map((revision) => (
                                                    <option
                                                        key={revision.id}
                                                        value={revision.id}
                                                    >
                                                        {revision.identifier}
                                                    </option>
                                                ))}
                                            </select>
                                            {revisionId && (
                                                <p className="font-mono text-xs break-all text-muted-foreground">
                                                    {t('ask.fingerprint')}:{' '}
                                                    {
                                                        revisions.find(
                                                            (item) =>
                                                                String(
                                                                    item.id,
                                                                ) ===
                                                                revisionId,
                                                        )?.fingerprint
                                                    }
                                                </p>
                                            )}
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="model">
                                                {t('ask.model')}
                                            </Label>
                                            <select
                                                id="model"
                                                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                                value={model}
                                                onChange={(event) =>
                                                    setModel(event.target.value)
                                                }
                                                disabled={
                                                    submitting || retryAvailable
                                                }
                                            >
                                                {configuration.models.map(
                                                    (item) => (
                                                        <option
                                                            key={item}
                                                            value={item}
                                                        >
                                                            {item}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="method">
                                                {t('ask.retrieval')}
                                            </Label>
                                            <select
                                                id="method"
                                                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                                value={method}
                                                onChange={(event) =>
                                                    setMethod(
                                                        event.target.value,
                                                    )
                                                }
                                                disabled={
                                                    submitting || retryAvailable
                                                }
                                            >
                                                {configuration.methods.map(
                                                    (item) => (
                                                        <option
                                                            key={item}
                                                            value={item}
                                                        >
                                                            {t(
                                                                `ask.methods.${item}`,
                                                            )}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="question">
                                                {t('ask.question')}
                                            </Label>
                                            <textarea
                                                id="question"
                                                className="min-h-32 w-full resize-y rounded-md border border-input bg-background px-3 py-2 text-sm outline-none placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50"
                                                maxLength={4000}
                                                value={question}
                                                onChange={(event) =>
                                                    setQuestion(
                                                        event.target.value,
                                                    )
                                                }
                                                disabled={
                                                    submitting || retryAvailable
                                                }
                                                placeholder={t(
                                                    'ask.question_placeholder',
                                                )}
                                            />
                                            {questionError && (
                                                <InputError
                                                    message={questionError}
                                                />
                                            )}
                                            <p className="text-right text-xs text-muted-foreground">
                                                {question.length}/4000
                                            </p>
                                        </div>
                                        {retryAvailable ? (
                                            <div className="space-y-2">
                                                <p className="text-xs text-muted-foreground">
                                                    {t(
                                                        'ask.retry_same_submission',
                                                    )}
                                                </p>
                                                <Button
                                                    className="w-full"
                                                    onClick={() =>
                                                        void submit(true)
                                                    }
                                                    disabled={submitting}
                                                >
                                                    {submitting && <Spinner />}
                                                    {t('ask.retry')}
                                                </Button>
                                                <Button
                                                    variant="outline"
                                                    className="w-full"
                                                    onClick={() => {
                                                        keyRef.current = null;
                                                        payloadRef.current =
                                                            null;
                                                        setRetryAvailable(
                                                            false,
                                                        );
                                                        setError('');
                                                    }}
                                                >
                                                    {t('ask.start_new')}
                                                </Button>
                                            </div>
                                        ) : (
                                            <Button
                                                className="w-full"
                                                onClick={() => void submit()}
                                                disabled={
                                                    submitting ||
                                                    !revisions.length
                                                }
                                            >
                                                {submitting && <Spinner />}
                                                {t('ask.submit')}
                                            </Button>
                                        )}
                                    </>
                                )}
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between gap-2">
                                <CardTitle>{t('ask.history')}</CardTitle>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => {
                                        setLoadingHistory(true);
                                        void loadHistory();
                                    }}
                                    disabled={loadingHistory}
                                >
                                    {loadingHistory && <Spinner />}
                                    {t('ask.refresh')}
                                </Button>
                            </CardHeader>
                            <CardContent className="space-y-2">
                                {loadingHistory && history.length === 0 ? (
                                    <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                        <Spinner />
                                        {t('ask.loading')}
                                    </div>
                                ) : history.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        {t('ask.empty_history')}
                                    </p>
                                ) : (
                                    history.map((item) => (
                                        <button
                                            type="button"
                                            key={item.id}
                                            onClick={() =>
                                                openQuestion(item.id)
                                            }
                                            className={`w-full min-w-0 rounded-md border p-3 text-left transition-colors hover:bg-accent ${detail?.id === item.id ? 'border-primary' : ''}`}
                                        >
                                            <span className="flex min-w-0 items-start justify-between gap-2">
                                                <span className="min-w-0 text-sm font-medium break-words">
                                                    {item.question}
                                                </span>
                                                <Badge
                                                    variant={
                                                        item.status ===
                                                        'completed'
                                                            ? 'default'
                                                            : 'secondary'
                                                    }
                                                    className="shrink-0"
                                                >
                                                    {statusLabel(item.status)}
                                                </Badge>
                                            </span>
                                            <span className="mt-1 block text-xs break-all text-muted-foreground">
                                                {item.revision}
                                            </span>
                                        </button>
                                    ))
                                )}
                            </CardContent>
                        </Card>
                    </div>
                    <section className="min-w-0 space-y-6" aria-live="polite">
                        {!detail && !loadingDetail ? (
                            <Card>
                                <CardContent className="py-12 text-center text-sm text-muted-foreground">
                                    {t('ask.select_or_submit')}
                                </CardContent>
                            </Card>
                        ) : loadingDetail && !detail ? (
                            <Card>
                                <CardContent className="flex items-center justify-center gap-2 py-12 text-sm text-muted-foreground">
                                    <Spinner />
                                    {t('ask.loading_detail')}
                                </CardContent>
                            </Card>
                        ) : (
                            detail && (
                                <>
                                    <Card>
                                        <CardHeader>
                                            <div className="flex flex-wrap items-start justify-between gap-3">
                                                <CardTitle className="min-w-0 break-words">
                                                    {detail.question}
                                                </CardTitle>
                                                <Badge
                                                    variant={
                                                        detail.status ===
                                                        'completed'
                                                            ? 'default'
                                                            : detail.status ===
                                                                'failed'
                                                              ? 'destructive'
                                                              : 'secondary'
                                                    }
                                                >
                                                    {statusLabel(detail.status)}
                                                </Badge>
                                            </div>
                                        </CardHeader>
                                        <CardContent className="space-y-4">
                                            <dl className="grid min-w-0 gap-3 text-sm sm:grid-cols-2">
                                                <div className="min-w-0">
                                                    <dt className="text-muted-foreground">
                                                        {t('ask.revision')}
                                                    </dt>
                                                    <dd className="font-mono text-xs break-all">
                                                        {detail.revision}
                                                    </dd>
                                                </div>
                                                <div>
                                                    <dt className="text-muted-foreground">
                                                        {t('ask.model')}
                                                    </dt>
                                                    <dd className="font-mono text-xs break-all">
                                                        {detail.model}
                                                    </dd>
                                                </div>
                                                <div>
                                                    <dt className="text-muted-foreground">
                                                        {t('ask.retrieval')}
                                                    </dt>
                                                    <dd>
                                                        {t(
                                                            `ask.methods.${detail.retrieval_method}`,
                                                        )}
                                                    </dd>
                                                </div>
                                                <div>
                                                    <dt className="text-muted-foreground">
                                                        {t('ask.created')}
                                                    </dt>
                                                    <dd>
                                                        {detail.created_at
                                                            ? new Date(
                                                                  detail.created_at,
                                                              ).toLocaleString()
                                                            : '—'}
                                                    </dd>
                                                </div>
                                            </dl>
                                            {detail.status === 'queued' ||
                                            detail.status === 'preparing' ||
                                            detail.status === 'running' ? (
                                                <p className="flex items-center gap-2 rounded-md bg-muted p-3 text-sm">
                                                    <Spinner />
                                                    {t(
                                                        `ask.status_help.${detail.status}`,
                                                    )}
                                                </p>
                                            ) : null}
                                            {detail.safe_error && (
                                                <p
                                                    role="alert"
                                                    className="rounded-md border border-destructive/40 p-3 text-sm break-words"
                                                >
                                                    {detail.safe_error}
                                                </p>
                                            )}
                                            {detail.answer && (
                                                <div className="space-y-2">
                                                    <h2 className="font-medium">
                                                        {t('ask.answer')}
                                                    </h2>
                                                    <pre className="max-h-[32rem] overflow-auto rounded-md border bg-muted/40 p-4 font-sans text-sm leading-relaxed break-words whitespace-pre-wrap">
                                                        {detail.answer}
                                                    </pre>
                                                    {detail.status ===
                                                        'incomplete' &&
                                                        detail.completion
                                                            .stop_reason && (
                                                            <p className="text-xs text-muted-foreground">
                                                                {t(
                                                                    'ask.stop_reason',
                                                                )}
                                                                :{' '}
                                                                {
                                                                    detail
                                                                        .completion
                                                                        .stop_reason
                                                                }
                                                            </p>
                                                        )}
                                                </div>
                                            )}
                                        </CardContent>
                                    </Card>
                                    <Card>
                                        <CardHeader>
                                            <CardTitle>
                                                {t('ask.sources')}
                                            </CardTitle>
                                            <p className="text-sm text-muted-foreground">
                                                {t('ask.sources_help')}
                                            </p>
                                        </CardHeader>
                                        <CardContent className="space-y-4">
                                            {detail.sources.length === 0 ? (
                                                <p className="text-sm text-muted-foreground">
                                                    {t('ask.no_sources')}
                                                </p>
                                            ) : (
                                                detail.sources.map(
                                                    (source, index) => (
                                                        <div
                                                            key={`${source.source}-${index}`}
                                                            className="min-w-0 overflow-hidden rounded-md border"
                                                        >
                                                            <h3 className="border-b bg-muted/50 px-3 py-2 font-mono text-xs break-all">
                                                                {source.source}
                                                            </h3>
                                                            <pre className="max-h-96 overflow-auto p-3 font-mono text-xs leading-5">
                                                                <code>
                                                                    {
                                                                        source.content
                                                                    }
                                                                </code>
                                                            </pre>
                                                        </div>
                                                    ),
                                                )
                                            )}
                                        </CardContent>
                                    </Card>
                                    <Card>
                                        <CardHeader>
                                            <CardTitle>
                                                {t('ask.metrics')}
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent>
                                            <dl className="grid min-w-0 gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                                                {[
                                                    [
                                                        t('ask.input_tokens'),
                                                        detail.metrics
                                                            .actual_input_tokens,
                                                    ],
                                                    [
                                                        t('ask.output_tokens'),
                                                        detail.metrics
                                                            .actual_output_tokens,
                                                    ],
                                                    [
                                                        t(
                                                            'ask.inference_duration',
                                                        ),
                                                        seconds == null
                                                            ? null
                                                            : `${seconds} s`,
                                                    ],
                                                    [
                                                        t('ask.wall_time'),
                                                        detail.metrics
                                                            .inference_wall_ms ==
                                                        null
                                                            ? null
                                                            : `${detail.metrics.inference_wall_ms} ms`,
                                                    ],
                                                    [
                                                        t(
                                                            'ask.estimated_tokens',
                                                        ),
                                                        detail.metrics
                                                            .estimated_prompt_tokens,
                                                    ],
                                                ].map(([label, value]) => (
                                                    <div
                                                        key={String(label)}
                                                        className="min-w-0 rounded-md border p-3"
                                                    >
                                                        <dt className="text-xs text-muted-foreground">
                                                            {label}
                                                        </dt>
                                                        <dd className="mt-1 font-mono break-words">
                                                            {value ?? '—'}
                                                        </dd>
                                                    </div>
                                                ))}
                                            </dl>
                                        </CardContent>
                                    </Card>
                                </>
                            )
                        )}
                    </section>
                </div>
            </main>
        </>
    );
}
