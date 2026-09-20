import { Head, Link } from '@inertiajs/react';
import { FolderOpen } from 'lucide-react';
import LocalSourcePanel from '@/components/projects/local-source-panel';
import ModelsPanel from '@/components/projects/models-panel';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { translate, useTranslations } from '@/hooks/use-translations';
import { index, show } from '@/routes/projects';
import type { Locale, LocalizationData, ProjectSummary } from '@/types';
import type { LaravelRouteSummary } from '@/types/laravel-route';
import type { LaravelModelSummary } from '@/types/laravel-model';
import type {
    LocalProjectSourceSummary,
    ProjectSourceEndpoints,
} from '@/types/local-project-source';

type Props = {
    project: ProjectSummary & {
        source: LocalProjectSourceSummary | null;
    };
    sourceEndpoints: ProjectSourceEndpoints | null;
    localSourceEnabled: boolean;
    localSourceConfigured: boolean;
    routes: LaravelRouteSummary[];
    models: LaravelModelSummary[];
};

function formatDate(date: string, locale: Locale): string {
    return new Intl.DateTimeFormat(locale === 'uk' ? 'uk-UA' : 'en-US', {
        dateStyle: 'medium',
    }).format(new Date(date));
}

export default function ProjectsShow({
    project,
    sourceEndpoints,
    localSourceEnabled,
    localSourceConfigured,
    routes,
    models,
}: Props) {
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

                {localSourceEnabled && sourceEndpoints ? (
                    <LocalSourcePanel
                        configured={localSourceConfigured}
                        endpoints={sourceEndpoints}
                        source={project.source}
                    />
                ) : (
                    <Card className="min-h-64 items-center justify-center text-center">
                        <CardContent className="flex max-w-xl flex-col items-center gap-4">
                            <div className="rounded-full bg-muted p-3">
                                <FolderOpen className="size-6 text-muted-foreground" />
                            </div>
                            <div className="space-y-1">
                                <h2 className="font-medium">
                                    {t('projects.show.empty_title')}
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    {t(
                                        'projects.show.no_sources_enabled_description',
                                    )}
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                )}

                <ModelsPanel models={models} />

                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <div>
                            <h2 className="font-medium">
                                {t('projects.routes.title')}
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                {t('projects.routes.description')}
                            </p>
                        </div>

                        {routes.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                {t('projects.routes.empty')}
                            </p>
                        ) : (
                            <div className="overflow-x-auto rounded-md border">
                                <table className="w-full text-left text-sm">
                                    <thead className="border-b bg-muted/50 text-xs text-muted-foreground">
                                        <tr>
                                            {[
                                                'method',
                                                'uri',
                                                'name',
                                                'controller',
                                                'middleware',
                                                'source',
                                            ].map((column) => (
                                                <th
                                                    key={column}
                                                    className="px-3 py-2 font-medium"
                                                >
                                                    {t(
                                                        `projects.routes.${column}`,
                                                    )}
                                                </th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {routes.map((route) => (
                                            <tr key={route.id}>
                                                <td className="px-3 py-2 align-top">
                                                    <Badge variant="outline">
                                                        {route.method}
                                                    </Badge>
                                                </td>
                                                <td className="px-3 py-2 align-top font-mono text-xs">
                                                    {route.uri}
                                                </td>
                                                <td className="px-3 py-2 align-top">
                                                    {route.name ?? '—'}
                                                </td>
                                                <td className="px-3 py-2 align-top font-mono text-xs">
                                                    {route.controller ?? '—'}
                                                </td>
                                                <td className="px-3 py-2 align-top">
                                                    {route.middleware.length > 0
                                                        ? route.middleware.join(
                                                              ', ',
                                                          )
                                                        : '—'}
                                                </td>
                                                <td className="px-3 py-2 align-top font-mono text-xs whitespace-nowrap">
                                                    {route.source_path}
                                                    {route.start_line !==
                                                        null &&
                                                        `:${route.start_line}`}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
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
