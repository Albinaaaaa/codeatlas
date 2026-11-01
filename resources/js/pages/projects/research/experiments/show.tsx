import { Form, Head, Link, usePoll } from '@inertiajs/react';
import { useEffect } from 'react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useTranslations } from '@/hooks/use-translations';
import type { ResearchProject } from '@/types/research';

type Experiment = {
    id: number;
    status: string;
    repetitions: number;
    total_cases: number;
    preparation_total: number;
    preparation_completed: number;
    attempted_cases: number;
    completed_cases: number;
    failed_cases: number;
    incomplete_cases: number;
    pending_cases: number;
    skipped_cases: number;
    safe_error: string | null;
    started_at: string | null;
    heartbeat_at: string | null;
    finished_at: string | null;
    dataset_sha256: string;
    revision_fingerprints: Record<string, string>;
    dataset: { name: string; version: string; revision: string };
};
type Props = {
    project: ResearchProject;
    experiment: Experiment;
    configuration: {
        protocol: string;
        models: string[];
        methods: string[];
    } | null;
    failures: {
        id: number;
        question_id: string;
        requested_model: string;
        method: string;
        repetition_index: number;
        status: string;
    }[];
    endpoints: {
        index: string;
        start: string;
        resume: string;
        evaluation: string;
        results: string;
    };
};

export default function ExperimentShow({
    project,
    experiment,
    configuration,
    failures,
    endpoints,
}: Props) {
    const { t } = useTranslations();
    const active = ['preparing', 'queued', 'running'].includes(
        experiment.status,
    );
    const { start, stop } = usePoll(
        2500,
        { only: ['experiment'] },
        { autoStart: false },
    );
    useEffect(() => {
        if (active) {
            start();
        } else {
            stop();
        }

        return () => stop();
    }, [active, start, stop]);
    const progress = experiment.total_cases
        ? Math.round(
              ((experiment.completed_cases +
                  experiment.failed_cases +
                  experiment.incomplete_cases) /
                  experiment.total_cases) *
                  100,
          )
        : 0;
    const resumable = ['partially_completed', 'interrupted'].includes(
        experiment.status,
    );

    return (
        <>
            <Head
                title={`${t('research.experiment')} #${experiment.id} · ${project.name}`}
            />
            <main className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {t('research.experiment')} #{experiment.id}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {experiment.dataset.name} ·{' '}
                            {experiment.dataset.version} ·{' '}
                            {experiment.dataset.revision}
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Badge variant="outline">
                            {t('research.status_' + experiment.status)}
                        </Badge>
                        <Button variant="outline" asChild>
                            <Link href={endpoints.evaluation}>
                                {t('research.evaluation')}
                            </Link>
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href={endpoints.results}>
                                {t('research.results')}
                            </Link>
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href={endpoints.index}>
                                {t('research.back')}
                            </Link>
                        </Button>
                    </div>
                </header>
                {experiment.safe_error && (
                    <Alert variant="destructive">
                        <AlertDescription>
                            {experiment.safe_error}
                        </AlertDescription>
                    </Alert>
                )}
                {experiment.status === 'queued' && (
                    <Alert>
                        <AlertDescription>
                            {t('research.experiment_waiting_for_worker')}
                        </AlertDescription>
                    </Alert>
                )}
                <Card>
                    <CardHeader>
                        <CardTitle>
                            {t('research.experiment_progress')}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="h-2 overflow-hidden rounded-full bg-muted">
                            <div
                                className="h-full bg-primary transition-all"
                                style={{ width: `${progress}%` }}
                            />
                        </div>
                        <p className="text-sm">
                            {experiment.completed_cases} /{' '}
                            {experiment.total_cases} · {progress}%
                        </p>
                        {experiment.status === 'preparing' && (
                            <p className="text-sm text-muted-foreground">
                                {t('research.preparation_progress')}:{' '}
                                {experiment.preparation_completed}/
                                {experiment.preparation_total}
                            </p>
                        )}
                        <dl className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                            {[
                                ['attempted_cases', experiment.attempted_cases],
                                ['completed_cases', experiment.completed_cases],
                                ['failed_cases', experiment.failed_cases],
                                [
                                    'incomplete_cases',
                                    experiment.incomplete_cases,
                                ],
                                ['pending_cases', experiment.pending_cases],
                                ['skipped_cases', experiment.skipped_cases],
                            ].map(([key, value]) => (
                                <div key={key}>
                                    <dt className="text-muted-foreground">
                                        {t('research.' + key)}
                                    </dt>
                                    <dd className="font-medium">{value}</dd>
                                </div>
                            ))}
                        </dl>
                        <p className="text-xs text-muted-foreground">
                            {t('research.last_activity')}:{' '}
                            {experiment.heartbeat_at ?? '—'}
                        </p>
                        {experiment.status === 'ready' && (
                            <Form action={endpoints.start} method="post">
                                {({ processing }) => (
                                    <Button disabled={processing}>
                                        {t('research.start_experiment')}
                                    </Button>
                                )}
                            </Form>
                        )}
                        {resumable && (
                            <Form action={endpoints.resume} method="post">
                                {({ processing }) => (
                                    <Button disabled={processing}>
                                        {t('research.resume_experiment')}
                                    </Button>
                                )}
                            </Form>
                        )}
                    </CardContent>
                </Card>
                {failures.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {t('research.failure_details')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {failures.map((failure) => (
                                <div
                                    key={failure.id}
                                    className="flex flex-wrap gap-x-3 gap-y-1 border-b pb-2 text-sm last:border-0"
                                >
                                    <span className="font-mono">
                                        {failure.question_id}
                                    </span>
                                    <span className="font-mono">
                                        {failure.requested_model}
                                    </span>
                                    <span>{failure.method}</span>
                                    <span>
                                        {t('research.repetition')}{' '}
                                        {failure.repetition_index}
                                    </span>
                                    <Badge variant="outline">
                                        {t('research.status_' + failure.status)}
                                    </Badge>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                )}
                {configuration && (
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {t('research.experiment_configuration')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2 text-sm">
                            <p>
                                {t('research.protocol')}:{' '}
                                <span className="font-mono">
                                    {configuration.protocol}
                                </span>
                            </p>
                            <p>
                                {t('research.repetitions')}:{' '}
                                {experiment.repetitions}
                            </p>
                            <p>
                                {t('research.models')}:{' '}
                                <span className="font-mono">
                                    {configuration.models.join(', ')}
                                </span>
                            </p>
                            <p>
                                {t('research.methods')}:{' '}
                                <span className="font-mono">
                                    {configuration.methods.join(', ')}
                                </span>
                            </p>
                            <p className="break-all">
                                SHA-256:{' '}
                                <span className="font-mono">
                                    {experiment.dataset_sha256}
                                </span>
                            </p>
                        </CardContent>
                    </Card>
                )}
            </main>
        </>
    );
}
