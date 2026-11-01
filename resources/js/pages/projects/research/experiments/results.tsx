import { Form, Head, Link } from '@inertiajs/react';
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
type Metric = { mean: number | null; n: number; unit: string };
type Group = {
    model: string;
    method: string;
    expected_cases: number;
    successful_cases: number;
    failed_cases: number;
    incomplete_cases: number;
    missing_cases: number;
    graded_cases: number;
    facts_covered: number;
    facts_total: number;
    metrics: Record<string, Metric>;
};
type Summary = {
    dataset_sha256: string;
    protocol_version: string;
    rubric_version: string;
    repetitions: number;
    selection_rule: string;
    groups: Group[];
};
type Props = {
    project: ResearchProject;
    experiment: { id: number; status: string };
    locked: boolean;
    progress: Progress;
    summary: Summary | null;
    safe_error: string | null;
    bundle: string | null;
    files: { name: string; url: string }[];
    endpoints: { export: string; evaluation: string };
};

export default function ExperimentResults({
    project,
    experiment,
    locked,
    progress,
    summary,
    safe_error,
    files,
    endpoints,
}: Props) {
    const { t } = useTranslations();

    return (
        <>
            <Head title={`${t('research.results')} · ${project.name}`} />
            <main className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-3">
                            <h1 className="text-2xl font-semibold tracking-tight break-words">
                                {t('research.results')}
                            </h1>
                            <Badge variant="secondary">
                                {t('research.results_type')}
                            </Badge>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {t('research.experiment')} #{experiment.id}
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Button variant="outline" asChild>
                            <Link href={endpoints.evaluation}>
                                {t('research.evaluation')}
                            </Link>
                        </Button>
                    </div>
                </header>
                {safe_error && (
                    <Alert variant="destructive">
                        <AlertDescription>{safe_error}</AlertDescription>
                    </Alert>
                )}
                <p className="max-w-3xl text-sm text-muted-foreground">
                    {t('research.results_help')}
                </p>
                {locked ? (
                    <>
                        <Card>
                            <CardHeader>
                                <CardTitle>
                                    {t('research.results_locked_title')}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <p className="text-sm text-muted-foreground">
                                    {t('research.results_locked')}
                                </p>
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
                                            <dd className="font-medium">
                                                {value}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                            </CardContent>
                        </Card>
                    </>
                ) : summary ? (
                    <>
                        <Card>
                            <CardHeader>
                                <CardTitle>
                                    {t('research.experiment_completeness')}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3 text-sm">
                                <p>
                                    {t('research.protocol')}:{' '}
                                    {summary.protocol_version}
                                    {' · '}
                                    {t('research.rubric')}:{' '}
                                    {summary.rubric_version}
                                    {' · '}
                                    {t('research.repetitions')}:{' '}
                                    {summary.repetitions}
                                </p>
                                <p className="font-mono text-xs break-all text-muted-foreground">
                                    SHA-256: {summary.dataset_sha256}
                                </p>
                                <div className="flex flex-wrap gap-2">
                                    <Badge variant="outline">
                                        {progress.successful}/
                                        {progress.expected}{' '}
                                        {t('research.successful')}
                                    </Badge>
                                    <Badge variant="outline">
                                        {progress.evaluated}/{progress.eligible}{' '}
                                        {t('research.evaluated_answers')}
                                    </Badge>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    {t('research.selection_rule')}
                                </p>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <CardTitle className="min-w-0">
                                        {t('research.comparison_table')}
                                    </CardTitle>
                                    <Form
                                        action={endpoints.export}
                                        method="post"
                                    >
                                        {({ processing }) => (
                                            <Button disabled={processing}>
                                                {processing
                                                    ? t('research.exporting')
                                                    : t(
                                                          'research.create_export',
                                                      )}
                                            </Button>
                                        )}
                                    </Form>
                                </div>
                            </CardHeader>
                            <CardContent>
                                <div className="overflow-x-auto">
                                    <table className="w-full min-w-[1400px] border-collapse text-left text-sm">
                                        <thead>
                                            <tr className="border-b text-muted-foreground">
                                                <th className="p-2">
                                                    {t('research.models')}
                                                </th>
                                                <th className="p-2">
                                                    {t('research.methods')}
                                                </th>
                                                <th className="p-2">
                                                    {t('research.cases')}
                                                </th>
                                                <th className="p-2">
                                                    {t('research.correctness')}
                                                </th>
                                                <th className="p-2">
                                                    {t(
                                                        'research.fact_coverage',
                                                    )}
                                                </th>
                                                <th className="p-2">
                                                    {t(
                                                        'research.actual_input_tokens',
                                                    )}
                                                </th>
                                                <th className="p-2">
                                                    {t(
                                                        'research.actual_output_tokens',
                                                    )}
                                                </th>
                                                <th className="p-2">
                                                    {t('research.total_time')}
                                                </th>
                                                <th className="p-2">
                                                    {t(
                                                        'research.inference_metrics',
                                                    )}
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {summary.groups.map((group) => (
                                                <tr
                                                    key={`${group.model}-${group.method}`}
                                                    className="border-b align-top"
                                                >
                                                    <th className="p-2 font-mono font-normal break-all">
                                                        {group.model}
                                                    </th>
                                                    <td className="p-2 font-mono break-all">
                                                        {group.method}
                                                    </td>
                                                    <td className="p-2">
                                                        {group.expected_cases}{' '}
                                                        {t(
                                                            'research.expected_short',
                                                        )}
                                                        <br />
                                                        {
                                                            group.successful_cases
                                                        }{' '}
                                                        {t(
                                                            'research.successful_short',
                                                        )}
                                                        <br />
                                                        {
                                                            group.failed_cases
                                                        }{' '}
                                                        {t(
                                                            'research.failed_short',
                                                        )}{' '}
                                                        ·{' '}
                                                        {group.incomplete_cases}{' '}
                                                        {t(
                                                            'research.incomplete_short',
                                                        )}{' '}
                                                        · {group.missing_cases}{' '}
                                                        {t(
                                                            'research.missing_short',
                                                        )}
                                                        <br />
                                                        {
                                                            group.graded_cases
                                                        }{' '}
                                                        {t(
                                                            'research.graded_short',
                                                        )}
                                                    </td>
                                                    <td className="p-2">
                                                        {metric(
                                                            group.metrics
                                                                .correctness,
                                                            'score_0_to_2',
                                                        )}
                                                    </td>
                                                    <td className="p-2">
                                                        {metric(
                                                            group.metrics
                                                                .required_fact_coverage,
                                                            'fraction',
                                                        )}
                                                        <br />
                                                        <span className="text-xs text-muted-foreground">
                                                            {
                                                                group.facts_covered
                                                            }
                                                            /{group.facts_total}{' '}
                                                            {t(
                                                                'research.facts',
                                                            )}
                                                        </span>
                                                    </td>
                                                    <td className="p-2">
                                                        {metric(
                                                            group.metrics
                                                                .prompt_eval_count,
                                                            'tokens',
                                                        )}
                                                    </td>
                                                    <td className="p-2">
                                                        {metric(
                                                            group.metrics
                                                                .eval_count,
                                                            'tokens',
                                                        )}
                                                    </td>
                                                    <td className="p-2">
                                                        {metric(
                                                            group.metrics
                                                                .total_wall,
                                                            'milliseconds',
                                                        )}
                                                    </td>
                                                    <td className="space-y-1 p-2">
                                                        {[
                                                            'inference_wall',
                                                            'context_construction',
                                                            'total_duration',
                                                            'load_duration',
                                                            'prompt_eval_duration',
                                                            'eval_duration',
                                                        ].map((name) => (
                                                            <div
                                                                key={name}
                                                                className="text-xs whitespace-nowrap"
                                                            >
                                                                <span className="text-muted-foreground">
                                                                    {t(
                                                                        'research.metric_' +
                                                                            name,
                                                                    )}
                                                                    :{' '}
                                                                </span>
                                                                {metric(
                                                                    group
                                                                        .metrics[
                                                                        name
                                                                    ],
                                                                    group
                                                                        .metrics[
                                                                        name
                                                                    ]?.unit ??
                                                                        '',
                                                                )}
                                                            </div>
                                                        ))}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                                <p className="mt-3 text-xs text-muted-foreground">
                                    {t('research.actual_metrics_note')}
                                </p>
                                <p className="mt-2 text-xs text-muted-foreground">
                                    {t('research.duration_help')}
                                </p>
                            </CardContent>
                        </Card>
                        {files.length > 0 && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>
                                        {t('research.private_exports')}
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="flex flex-wrap gap-3">
                                    {files.map((file) => (
                                        <Button
                                            key={file.name}
                                            variant="outline"
                                            asChild
                                        >
                                            <a href={file.url} download>
                                                {file.name}
                                            </a>
                                        </Button>
                                    ))}
                                </CardContent>
                            </Card>
                        )}
                    </>
                ) : null}
            </main>
        </>
    );
}

function metric(value: Metric | undefined, fallbackUnit: string): string {
    if (!value || value.mean === null) {
        return `— (n=0)`;
    }

    const unit = value.unit || fallbackUnit;

    return `${Number(value.mean.toFixed(2))} ${unit} (n=${value.n})`;
}
