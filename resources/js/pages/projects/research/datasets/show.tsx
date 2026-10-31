import { Form, Head, Link, router } from '@inertiajs/react';
import InputError from '@/components/input-error';
import QuestionEditor from '@/components/research/question-editor';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { translate, useTranslations } from '@/hooks/use-translations';
import { index, show } from '@/routes/projects';
import type { LocalizationData } from '@/types';
import type { ResearchDataset, ResearchProject } from '@/types/research';

type Props = {
    project: ResearchProject;
    dataset: ResearchDataset;
    endpoints: { index: string; show: string };
};

export default function DatasetShow({ project, dataset, endpoints }: Props) {
    const { t } = useTranslations();
    const frozen = dataset.status === 'frozen';
    const report = dataset.validation_report;

    return (
        <>
            <Head title={dataset.dataset_id + ' · ' + project.name} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-2">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {dataset.dataset_id} · {dataset.dataset_version}
                        </h1>
                        <div className="flex gap-2">
                            <Badge>{t('research.' + dataset.status)}</Badge>
                            <Badge variant="outline">
                                {t('research.' + dataset.split)}
                            </Badge>
                        </div>
                    </div>
                    <Button variant="outline" asChild>
                        <Link href={endpoints.index}>{t('research.back')}</Link>
                    </Button>
                </div>
                <Card>
                    <CardContent className="space-y-3 pt-6">
                        <div>
                            <h2 className="text-sm font-medium">
                                {t('research.revision')}
                            </h2>
                            <p className="font-mono text-xs break-all">
                                {dataset.revision.identifier}
                            </p>
                        </div>
                        {dataset.original_sha256 && (
                            <div>
                                <h2 className="text-sm font-medium">
                                    {t('research.original_hash')}
                                </h2>
                                <p className="font-mono text-xs break-all">
                                    {dataset.original_sha256}
                                </p>
                            </div>
                        )}
                        {dataset.frozen_sha256 && (
                            <div>
                                <h2 className="text-sm font-medium">
                                    {t('research.frozen_hash')}
                                </h2>
                                <p className="font-mono text-xs break-all">
                                    {dataset.frozen_sha256}
                                </p>
                            </div>
                        )}
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => router.reload()}
                        >
                            {t('research.reload')}
                        </Button>
                    </CardContent>
                </Card>
                {report && (
                    <Alert
                        variant={
                            report.errors.length ? 'destructive' : 'default'
                        }
                    >
                        <AlertDescription>
                            {report.errors.length === 0 ? (
                                t('research.valid')
                            ) : (
                                <div className="space-y-2">
                                    <p>{t('research.validation_failed')}</p>
                                    <ul className="list-inside list-disc">
                                        {report.errors.map((error, i) => (
                                            <li key={i}>
                                                <a
                                                    className="underline"
                                                    href={
                                                        '#question-' +
                                                        (error.line - 1)
                                                    }
                                                >
                                                    {t('research.line')}{' '}
                                                    {error.line}
                                                    {error.question_id
                                                        ? ' · ' +
                                                          error.question_id
                                                        : ''}
                                                </a>
                                                {error.field !== 'dataset' && (
                                                    <span className="font-mono">
                                                        {' '}
                                                        ·{' '}
                                                        {error.field.replaceAll(
                                                            '_',
                                                            ' ',
                                                        )}
                                                    </span>
                                                )}
                                                : {error.message}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}
                        </AlertDescription>
                    </Alert>
                )}
                {!frozen && (
                    <div className="flex flex-wrap gap-3">
                        {(['questions', 'validate'] as const).map((action) => (
                            <Form
                                key={action}
                                action={endpoints.show + '/' + action}
                                method="post"
                                options={{ preserveScroll: true }}
                            >
                                {({ processing, errors }) => (
                                    <div className="space-y-2">
                                        <input
                                            type="hidden"
                                            name="lock_version"
                                            value={dataset.lock_version}
                                        />
                                        <Button
                                            variant="outline"
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            {t(
                                                action === 'questions'
                                                    ? 'research.add_question'
                                                    : 'research.validate',
                                            )}
                                        </Button>
                                        {Object.entries(errors).map(
                                            ([field, message]) => (
                                                <InputError
                                                    key={field}
                                                    message={message}
                                                />
                                            ),
                                        )}
                                    </div>
                                )}
                            </Form>
                        ))}
                        <Dialog>
                            <DialogTrigger asChild>
                                <Button>{t('research.freeze')}</Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>
                                        {t('research.freeze')}
                                    </DialogTitle>
                                    <DialogDescription>
                                        {t('research.freeze_help')}
                                    </DialogDescription>
                                </DialogHeader>
                                <Form
                                    action={endpoints.show + '/freeze'}
                                    method="post"
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing, errors }) => (
                                        <div className="space-y-4">
                                            <input
                                                type="hidden"
                                                name="lock_version"
                                                value={dataset.lock_version}
                                            />
                                            {Object.entries(errors).map(
                                                ([field, message]) => (
                                                    <InputError
                                                        key={field}
                                                        message={message}
                                                    />
                                                ),
                                            )}
                                            <DialogFooter>
                                                <DialogClose asChild>
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                    >
                                                        {t('research.cancel')}
                                                    </Button>
                                                </DialogClose>
                                                <Button disabled={processing}>
                                                    {processing && <Spinner />}
                                                    {t('research.freeze')}
                                                </Button>
                                            </DialogFooter>
                                        </div>
                                    )}
                                </Form>
                            </DialogContent>
                        </Dialog>
                    </div>
                )}
                {dataset.questions.length === 0 && (
                    <p className="text-muted-foreground">
                        {t('research.no_questions')}
                    </p>
                )}
                {dataset.questions.map((question, position) => (
                    <QuestionEditor
                        key={position + JSON.stringify(question)}
                        question={question}
                        position={position}
                        base={endpoints.show}
                        version={dataset.lock_version}
                        frozen={frozen}
                        errors={
                            report?.errors.filter(
                                (error) => error.line === position + 1,
                            ) ?? []
                        }
                    />
                ))}
            </div>
        </>
    );
}

DatasetShow.layout = ({
    project,
    dataset,
    endpoints,
    localization,
}: Props & { localization: LocalizationData }) => ({
    breadcrumbs: [
        {
            title: translate(localization.translations, 'navigation.projects'),
            href: index(),
        },
        { title: project.name, href: show(project.id) },
        {
            title: translate(localization.translations, 'research.datasets'),
            href: endpoints.index,
        },
        { title: dataset.dataset_id, href: endpoints.show },
    ],
});
