import { Head, Link } from '@inertiajs/react';
import { FolderOpen } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { translate, useTranslations } from '@/hooks/use-translations';
import { index, show } from '@/routes/projects';
import type { Locale, LocalizationData, ProjectSummary } from '@/types';

type Props = {
    project: ProjectSummary;
};

function formatDate(date: string, locale: Locale): string {
    return new Intl.DateTimeFormat(locale === 'uk' ? 'uk-UA' : 'en-US', {
        dateStyle: 'medium',
    }).format(new Date(date));
}

export default function ProjectsShow({ project }: Props) {
    const { locale, t } = useTranslations();

    return (
        <>
            <Head title={project.name} />

            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto p-4 md:p-6">
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                    <div className="space-y-2">
                        <div className="flex flex-wrap items-center gap-3">
                            <h1 className="text-2xl font-semibold tracking-tight">
                                {project.name}
                            </h1>
                            <Badge
                                variant={
                                    project.status === 'connected'
                                        ? 'default'
                                        : 'secondary'
                                }
                            >
                                {t(`projects.status.${project.status}`)}
                            </Badge>
                        </div>
                        {project.description && (
                            <p className="max-w-3xl text-sm text-muted-foreground">
                                {project.description}
                            </p>
                        )}
                        <time
                            dateTime={project.created_at}
                            className="block text-sm text-muted-foreground"
                        >
                            {t('projects.created_at', {
                                date: formatDate(project.created_at, locale),
                            })}
                        </time>
                    </div>

                    <Button variant="outline" asChild>
                        <Link href={index()}>{t('projects.show.back')}</Link>
                    </Button>
                </div>

                <Card className="min-h-64 items-center justify-center text-center">
                    <CardContent className="flex flex-col items-center gap-3">
                        <div className="rounded-full bg-muted p-3">
                            <FolderOpen className="size-6 text-muted-foreground" />
                        </div>
                        <div className="space-y-1">
                            <h2 className="font-medium">
                                {t('projects.show.empty_title')}
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                {t('projects.show.empty_description')}
                            </p>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

ProjectsShow.layout = ({
    project,
    localization,
}: Props & { localization: LocalizationData }) => ({
    breadcrumbs: [
        {
            title: translate(localization.translations, 'navigation.projects'),
            href: index(),
        },
        {
            title: project.name,
            href: show(project.id),
        },
    ],
});
