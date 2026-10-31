import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { translate, useTranslations } from '@/hooks/use-translations';
import type { LocalizationData } from '@/types';
import type { ResearchProject } from '@/types/research';

type Dataset = {
    id: number;
    dataset_id: string;
    version: string;
    sha256: string;
    revision: string;
    fingerprint: string;
    questions: number;
};
type Experiment = {
    id: number;
    status: string;
    total_cases: number;
    completed_cases: number;
    dataset: { name: string; version: string };
};
type Props = {
    project: ResearchProject;
    datasets: Dataset[];
    experiments: Experiment[];
    configuration: { protocol: string; models: string[]; methods: string[] };
    endpoints: { store: string; show: string };
};

export default function ExperimentsIndex({
    project,
    datasets,
    experiments,
    configuration,
    endpoints,
}: Props) {
    const { t } = useTranslations();
    const [datasetId, setDatasetId] = useState('');
    const [repetitions, setRepetitions] = useState(1);
    const selected = datasets.find(
        (dataset) => String(dataset.id) === datasetId,
    );

    return (
        <>
            <Head title={t('research.experiments') + ' · ' + project.name} />
            <main className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <header>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {t('research.experiments')}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('research.experiment_description')}
                    </p>
                </header>
                <Card>
                    <CardHeader>
                        <CardTitle>
                            {t('research.prepare_experiment')}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {datasets.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                {t('research.no_frozen_datasets')}
                            </p>
                        ) : (
                            <Form
                                action={endpoints.store}
                                method="post"
                                className="max-w-3xl space-y-4"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <div className="space-y-2">
                                            <Label htmlFor="dataset_id">
                                                {t('research.dataset')}
                                            </Label>
                                            <Select
                                                name="dataset_id"
                                                value={datasetId}
                                                onValueChange={setDatasetId}
                                            >
                                                <SelectTrigger id="dataset_id">
                                                    <SelectValue
                                                        placeholder={t(
                                                            'research.select_dataset',
                                                        )}
                                                    />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {datasets.map((dataset) => (
                                                        <SelectItem
                                                            key={dataset.id}
                                                            value={String(
                                                                dataset.id,
                                                            )}
                                                        >
                                                            {dataset.dataset_id}{' '}
                                                            · {dataset.version}{' '}
                                                            — {dataset.revision}{' '}
                                                            ({dataset.questions}{' '}
                                                            {t(
                                                                'research.questions',
                                                            )}
                                                            )
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            <InputError
                                                message={errors.dataset_id}
                                            />
                                        </div>
                                        {selected && (
                                            <div className="rounded-md border p-3 text-sm">
                                                <p>
                                                    {t('research.revision')}:{' '}
                                                    {selected.revision}
                                                </p>
                                                <p className="font-mono text-xs break-all">
                                                    {selected.fingerprint}
                                                </p>
                                                <p className="font-mono text-xs break-all">
                                                    SHA-256: {selected.sha256}
                                                </p>
                                            </div>
                                        )}
                                        <div className="space-y-2">
                                            <Label htmlFor="repetitions">
                                                {t('research.repetitions')}
                                            </Label>
                                            <Select
                                                name="repetitions"
                                                value={String(repetitions)}
                                                onValueChange={(value) =>
                                                    setRepetitions(
                                                        Number(value),
                                                    )
                                                }
                                            >
                                                <SelectTrigger id="repetitions">
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {[1, 2, 3, 4, 5].map(
                                                        (value) => (
                                                            <SelectItem
                                                                key={value}
                                                                value={String(
                                                                    value,
                                                                )}
                                                            >
                                                                {value}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                            <InputError
                                                message={errors.repetitions}
                                            />
                                        </div>
                                        <div className="rounded-md border p-3 text-sm">
                                            <p>
                                                {t('research.protocol')}:{' '}
                                                <span className="font-mono">
                                                    {configuration.protocol}
                                                </span>
                                            </p>
                                            <p>
                                                {t('research.models')}:{' '}
                                                <span className="font-mono">
                                                    {configuration.models.join(
                                                        ', ',
                                                    )}
                                                </span>
                                            </p>
                                            <p>
                                                {t('research.methods')}:{' '}
                                                <span className="font-mono">
                                                    {configuration.methods.join(
                                                        ', ',
                                                    )}
                                                </span>
                                            </p>
                                            <p className="mt-2 font-medium">
                                                {t('research.expected_cases')}:{' '}
                                                {selected
                                                    ? selected.questions *
                                                      9 *
                                                      repetitions
                                                    : '—'}
                                            </p>
                                            <p className="mt-2 text-muted-foreground">
                                                {t('research.matrix_help')}
                                            </p>
                                        </div>
                                        <Button disabled={processing}>
                                            {processing && (
                                                <span className="mr-2">…</span>
                                            )}
                                            {t('research.prepare_experiment')}
                                        </Button>
                                    </>
                                )}
                            </Form>
                        )}
                    </CardContent>
                </Card>
                <section className="space-y-3">
                    <h2 className="text-lg font-medium">
                        {t('research.experiment_list')}
                    </h2>
                    {experiments.length === 0 ? (
                        <Card>
                            <CardContent className="py-8 text-sm text-muted-foreground">
                                {t('research.no_experiments')}
                            </CardContent>
                        </Card>
                    ) : (
                        experiments.map((experiment) => (
                            <Card key={experiment.id}>
                                <CardContent className="flex flex-wrap items-center justify-between gap-3 pt-6">
                                    <div>
                                        <p className="font-medium">
                                            {experiment.dataset.name} ·{' '}
                                            {experiment.dataset.version}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            {experiment.completed_cases}/
                                            {experiment.total_cases}
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-3">
                                        <Badge variant="outline">
                                            {t(
                                                'research.status_' +
                                                    experiment.status,
                                            )}
                                        </Badge>
                                        <Button variant="outline" asChild>
                                            <Link
                                                href={endpoints.show.replace(
                                                    '__EXPERIMENT__',
                                                    String(experiment.id),
                                                )}
                                            >
                                                {t('research.open')}
                                            </Link>
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>
                        ))
                    )}
                </section>
            </main>
        </>
    );
}

export function headTitle(localization: LocalizationData): string {
    return translate(localization.translations, 'research.experiments');
}
