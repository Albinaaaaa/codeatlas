import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { translate, useTranslations } from '@/hooks/use-translations';
import { index, show } from '@/routes/projects';
import type { LocalizationData } from '@/types';
import type { ResearchProject, ResearchRevision } from '@/types/research';

type Props = {
    project: ResearchProject;
    endpoints: { index: string };
    revisions: ResearchRevision[];
    datasets: {
        id: number;
        dataset_id: string;
        dataset_version: string;
        split: string;
        status: string;
        url: string;
    }[];
};

export default function DatasetsIndex({
    project,
    endpoints,
    revisions,
    datasets,
}: Props) {
    const { t } = useTranslations();

    return (
        <>
            <Head title={t('research.datasets') + ' · ' + project.name} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {t('research.datasets')}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('research.description')}
                    </p>
                </div>
                <Card>
                    <CardHeader>
                        <CardTitle>{t('research.create')}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {revisions.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                {t('research.no_revisions')}
                            </p>
                        ) : (
                            <Form
                                action={endpoints.index}
                                method="post"
                                className="max-w-2xl space-y-4"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <div className="space-y-2">
                                            <Label htmlFor="revision">
                                                {t('research.revision')}
                                            </Label>
                                            <Select name="revision_id">
                                                <SelectTrigger
                                                    id="revision"
                                                    className="w-full"
                                                >
                                                    <SelectValue
                                                        placeholder={t(
                                                            'research.select_revision',
                                                        )}
                                                    />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {revisions.map(
                                                        (revision) => (
                                                            <SelectItem
                                                                key={
                                                                    revision.id
                                                                }
                                                                value={String(
                                                                    revision.id,
                                                                )}
                                                            >
                                                                {
                                                                    revision.identifier
                                                                }
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                            <InputError
                                                message={errors.revision_id}
                                            />
                                        </div>
                                        <div className="grid gap-4 sm:grid-cols-2">
                                            <div className="space-y-2">
                                                <Label htmlFor="dataset_id">
                                                    {t('research.dataset_id')}
                                                </Label>
                                                <Input
                                                    id="dataset_id"
                                                    name="dataset_id"
                                                    maxLength={128}
                                                />
                                                <InputError
                                                    message={errors.dataset_id}
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label htmlFor="dataset_version">
                                                    {t(
                                                        'research.dataset_version',
                                                    )}
                                                </Label>
                                                <Input
                                                    id="dataset_version"
                                                    name="dataset_version"
                                                    placeholder="0.1.0"
                                                    maxLength={32}
                                                />
                                                <InputError
                                                    message={
                                                        errors.dataset_version
                                                    }
                                                />
                                            </div>
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="split">
                                                {t('research.split')}
                                            </Label>
                                            <Select
                                                name="split"
                                                defaultValue="pilot"
                                            >
                                                <SelectTrigger id="split">
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="pilot">
                                                        {t('research.pilot')}
                                                    </SelectItem>
                                                    <SelectItem value="evaluation">
                                                        {t(
                                                            'research.evaluation',
                                                        )}
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>
                                            <InputError
                                                message={errors.split}
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="upload">
                                                {t('research.upload')}
                                            </Label>
                                            <Input
                                                id="upload"
                                                name="upload"
                                                type="file"
                                                accept=".jsonl,application/json,text/plain"
                                            />
                                            <InputError
                                                message={errors.upload}
                                            />
                                            <p className="text-sm text-muted-foreground">
                                                {t('research.import_help')}
                                            </p>
                                        </div>
                                        {Object.entries(errors)
                                            .filter(
                                                ([key]) =>
                                                    ![
                                                        'revision_id',
                                                        'dataset_id',
                                                        'dataset_version',
                                                        'split',
                                                        'upload',
                                                    ].includes(key),
                                            )
                                            .map(([key, error]) => (
                                                <InputError
                                                    key={key}
                                                    message={key + ': ' + error}
                                                />
                                            ))}
                                        <Button disabled={processing}>
                                            {processing && <Spinner />}
                                            {t('research.create')}
                                        </Button>
                                    </>
                                )}
                            </Form>
                        )}
                    </CardContent>
                </Card>
                {datasets.length === 0 ? (
                    <Card>
                        <CardContent className="py-8 text-muted-foreground">
                            {t('research.empty')}
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid gap-4 md:grid-cols-2">
                        {datasets.map((dataset) => (
                            <Card key={dataset.id}>
                                <CardContent className="space-y-4 pt-6">
                                    <div className="flex flex-wrap items-center gap-3">
                                        <h2 className="font-medium">
                                            {dataset.dataset_id} ·{' '}
                                            {dataset.dataset_version}
                                        </h2>
                                        <Badge variant="secondary">
                                            {t('research.' + dataset.status)}
                                        </Badge>
                                        <Badge variant="outline">
                                            {t('research.' + dataset.split)}
                                        </Badge>
                                    </div>
                                    <Button variant="outline" asChild>
                                        <Link href={dataset.url}>
                                            {t('research.open')}
                                        </Link>
                                    </Button>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

DatasetsIndex.layout = ({
    project,
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
    ],
});
