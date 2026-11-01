import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useTranslations } from '@/hooks/use-translations';
import type { ResearchProject } from '@/types/research';

type Progress = {
    expected: number;
    successful: number;
    failed: number;
    incomplete: number;
    missing: number;
    pending: number;
    eligible: number;
    evaluated: number;
};
type Answer = {
    evaluation_id: string;
    rubric_version: string;
    question: string;
    reference_answer: string;
    required_facts: string[];
    answer: string;
    correctness: number | null;
    fact_coverage: boolean[] | null;
    notes: string | null;
};
type Props = {
    project: ResearchProject;
    experiment: { id: number; status: string };
    locked: boolean;
    progress: Progress;
    answers: Answer[];
    safe_error: string | null;
    endpoints: { store: string; results: string };
};

export default function EvaluateExperiment({
    project,
    experiment,
    locked,
    progress,
    answers,
    safe_error,
    endpoints,
}: Props) {
    const { t } = useTranslations();

    return (
        <>
            <Head title={`${t('research.evaluation')} · ${project.name}`} />
            <main className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-3">
                            <h1 className="text-2xl font-semibold tracking-tight break-words">
                                {t('research.evaluation')}
                            </h1>
                            <Badge variant="secondary">
                                {t('research.evaluation_type')}
                            </Badge>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {t('research.experiment')} #{experiment.id}
                        </p>
                    </div>
                    <Button variant="outline" asChild>
                        <Link href={endpoints.results}>
                            {t('research.results')}
                        </Link>
                    </Button>
                </header>
                {safe_error && (
                    <Alert variant="destructive">
                        <AlertDescription>{safe_error}</AlertDescription>
                    </Alert>
                )}
                <p className="max-w-3xl text-sm text-muted-foreground">
                    {t('research.evaluation_help')}
                </p>
                <Card>
                    <CardHeader>
                        <CardTitle>
                            {t('research.evaluation_progress')}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="flex flex-wrap gap-2">
                            <Badge variant="outline">
                                {progress.evaluated}/{progress.eligible}{' '}
                                {t('research.evaluated_answers')}
                            </Badge>
                            <Badge variant="outline">
                                {t('research.status_' + experiment.status)}
                            </Badge>
                        </div>
                        <dl className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                            {[
                                ['expected', progress.expected],
                                ['successful', progress.successful],
                                ['failed', progress.failed],
                                ['incomplete', progress.incomplete],
                                ['missing', progress.missing],
                                ['pending', progress.pending],
                                ['eligible', progress.eligible],
                                ['evaluated', progress.evaluated],
                            ].map(([key, value]) => (
                                <div key={key}>
                                    <dt className="text-muted-foreground">
                                        {t('research.progress_' + key)}
                                    </dt>
                                    <dd className="font-medium">{value}</dd>
                                </div>
                            ))}
                        </dl>
                    </CardContent>
                </Card>
                {locked ? (
                    <Alert>
                        <AlertDescription>
                            {t('research.evaluation_locked')}
                        </AlertDescription>
                    </Alert>
                ) : answers.length === 0 ? (
                    <Card>
                        <CardContent className="py-8 text-sm text-muted-foreground">
                            {t('research.no_answers_to_evaluate')}
                        </CardContent>
                    </Card>
                ) : (
                    answers.map((answer) => (
                        <EvaluationCard
                            key={answer.evaluation_id}
                            answer={answer}
                            endpoint={endpoints.store}
                        />
                    ))
                )}
            </main>
        </>
    );
}

function EvaluationCard({
    answer,
    endpoint,
}: {
    answer: Answer;
    endpoint: string;
}) {
    const { t } = useTranslations();
    const [saved, setSaved] = useState(false);
    const form = useForm({
        evaluation_id: answer.evaluation_id,
        correctness: answer.correctness,
        fact_coverage:
            answer.fact_coverage ??
            answer.required_facts.map(() => null as boolean | null),
        notes: answer.notes ?? '',
    });
    const complete =
        form.data.correctness !== null &&
        form.data.fact_coverage.every((value) => value !== null);

    return (
        <Card className="min-w-0">
            <CardHeader>
                <CardTitle className="text-base">
                    {t('research.blind_answer')}
                </CardTitle>
                <p className="font-mono text-xs break-all text-muted-foreground">
                    {answer.evaluation_id.slice(0, 12)} · {t('research.rubric')}{' '}
                    {answer.rubric_version}
                </p>
            </CardHeader>
            <CardContent className="space-y-5">
                <section className="space-y-2">
                    <h2 className="text-sm font-medium">
                        {t('research.question')}
                    </h2>
                    <p className="text-sm break-words whitespace-pre-wrap">
                        {answer.question}
                    </p>
                </section>
                <section className="space-y-2">
                    <h2 className="text-sm font-medium">
                        {t('research.verified_reference_answer')}
                    </h2>
                    <p className="text-sm break-words whitespace-pre-wrap">
                        {answer.reference_answer}
                    </p>
                </section>
                <section className="space-y-2">
                    <h2 className="text-sm font-medium">
                        {t('research.required_facts')}
                    </h2>
                    <ol className="list-inside list-decimal space-y-2 text-sm">
                        {answer.required_facts.map((fact, index) => (
                            <li key={`${answer.evaluation_id}-${index}`}>
                                {fact}
                                <fieldset className="mt-1 flex gap-4 pl-5">
                                    <legend className="sr-only">
                                        {t('research.fact_coverage')}
                                    </legend>
                                    {[true, false].map((value) => (
                                        <label
                                            className="inline-flex cursor-pointer items-center gap-2"
                                            key={String(value)}
                                        >
                                            <input
                                                type="radio"
                                                name={`fact-${answer.evaluation_id}-${index}`}
                                                checked={
                                                    form.data.fact_coverage[
                                                        index
                                                    ] === value
                                                }
                                                onChange={() => {
                                                    const next = [
                                                        ...form.data
                                                            .fact_coverage,
                                                    ];
                                                    next[index] = value;
                                                    form.setData(
                                                        'fact_coverage',
                                                        next,
                                                    );
                                                    setSaved(false);
                                                }}
                                            />
                                            {value
                                                ? t('research.covered')
                                                : t('research.not_covered')}
                                        </label>
                                    ))}
                                </fieldset>
                            </li>
                        ))}
                    </ol>
                    <InputError message={form.errors.fact_coverage} />
                </section>
                <section className="space-y-2">
                    <h2 className="text-sm font-medium">
                        {t('research.generated_answer')}
                    </h2>
                    <pre className="max-h-[32rem] overflow-auto rounded-md border bg-muted/40 p-4 font-mono text-sm break-words whitespace-pre-wrap">
                        {answer.answer}
                    </pre>
                </section>
                <fieldset className="space-y-2">
                    <legend className="text-sm font-medium">
                        {t('research.correctness')}
                    </legend>
                    <div className="flex flex-wrap gap-4">
                        {[
                            [0, 'correctness_0'],
                            [1, 'correctness_1'],
                            [2, 'correctness_2'],
                        ].map(([score, label]) => (
                            <label
                                key={score}
                                className="inline-flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-sm"
                            >
                                <input
                                    type="radio"
                                    name={`correctness-${answer.evaluation_id}`}
                                    checked={form.data.correctness === score}
                                    onChange={() => {
                                        form.setData(
                                            'correctness',
                                            Number(score),
                                        );
                                        setSaved(false);
                                    }}
                                />
                                {t('research.' + label)}
                            </label>
                        ))}
                    </div>
                    <InputError message={form.errors.correctness} />
                </fieldset>
                <label className="block space-y-2 text-sm font-medium">
                    {t('research.evaluation_notes')}
                    <textarea
                        className="min-h-24 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                        value={form.data.notes}
                        maxLength={8000}
                        onChange={(event) => {
                            form.setData('notes', event.target.value);
                            setSaved(false);
                        }}
                    />
                    <InputError message={form.errors.notes} />
                </label>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(endpoint, {
                            preserveScroll: true,
                            onSuccess: () => setSaved(true),
                        });
                    }}
                >
                    <input
                        type="hidden"
                        name="evaluation_id"
                        value={answer.evaluation_id}
                    />
                    <Button disabled={!complete || form.processing}>
                        {form.processing
                            ? t('research.saving_evaluation')
                            : t('research.save_evaluation')}
                    </Button>
                    {saved && (
                        <span className="ml-3 text-sm text-muted-foreground">
                            {t('research.evaluation_saved')}
                        </span>
                    )}
                </form>
            </CardContent>
        </Card>
    );
}
