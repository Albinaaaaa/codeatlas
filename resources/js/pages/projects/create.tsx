import { Form, Head, Link } from '@inertiajs/react';
import ProjectController from '@/actions/App/Http/Controllers/ProjectController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { translate, useTranslations } from '@/hooks/use-translations';
import { create, index } from '@/routes/projects';
import type { LocalizationData } from '@/types';

export default function ProjectsCreate() {
    const { t } = useTranslations();

    return (
        <>
            <Head title={t('projects.create.title')} />

            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {t('projects.create.title')}
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {t('projects.create.description')}
                    </p>
                </div>

                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle>
                            {t('projects.create.details_title')}
                        </CardTitle>
                        <CardDescription>
                            {t('projects.create.details_description')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Form
                            {...ProjectController.store.form()}
                            className="space-y-6"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="name">
                                            {t('projects.fields.name')}
                                        </Label>
                                        <Input
                                            id="name"
                                            name="name"
                                            required
                                            autoFocus
                                            autoComplete="off"
                                            placeholder={t(
                                                'projects.fields.name_placeholder',
                                            )}
                                        />
                                        <InputError message={errors.name} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="description">
                                            {t('projects.fields.description')}{' '}
                                            <span className="font-normal text-muted-foreground">
                                                {t('projects.fields.optional')}
                                            </span>
                                        </Label>
                                        <textarea
                                            id="description"
                                            name="description"
                                            rows={5}
                                            placeholder={t(
                                                'projects.fields.description_placeholder',
                                            )}
                                            className="flex min-h-28 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-input/30"
                                        />
                                        <InputError
                                            message={errors.description}
                                        />
                                    </div>

                                    <div className="flex items-center gap-3">
                                        <Button disabled={processing}>
                                            {t('projects.create.submit')}
                                        </Button>
                                        <Button variant="outline" asChild>
                                            <Link href={index()}>
                                                {t('projects.create.cancel')}
                                            </Link>
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

ProjectsCreate.layout = ({
    localization,
}: {
    localization: LocalizationData;
}) => ({
    breadcrumbs: [
        {
            title: translate(localization.translations, 'navigation.projects'),
            href: index(),
        },
        {
            title: translate(
                localization.translations,
                'projects.create.breadcrumb',
            ),
            href: create(),
        },
    ],
});
