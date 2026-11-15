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
import type { LaravelModelSummary } from '@/types/laravel-model';
import type { LaravelRouteSummary } from '@/types/laravel-route';
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
    database: {
        tables: Array<{
            id: number;
            name: string;
            schema_name: string;
            metadata: Record<string, unknown>;
        }>;
        columns: Array<{
            id: number;
            database_table_id: number;
            name: string;
            data_type: string;
            native_type: string | null;
            is_nullable: boolean;
            default_value: string | null;
            metadata: Record<string, unknown>;
        }>;
        indexes: Array<{
            id: number;
            database_table_id: number;
            name: string;
            is_unique: boolean;
            is_primary: boolean;
            columns: string[];
            metadata: Record<string, unknown>;
        }>;
        foreign_keys: Array<{
            id: number;
            database_table_id: number;
            name: string;
            referenced_table_name: string;
            columns: Array<{ column: string; referenced_column: string }>;
            metadata: Record<string, unknown>;
        }>;
    };
    overview: {
        revision: {
            identifier: string;
            branch: string | null;
            commit_hash: string | null;
            message: string | null;
        } | null;
        last_analysis: {
            status: string;
            started_at: string | null;
            completed_at: string | null;
            failure_reason: string | null;
        } | null;
        profile: {
            summary: string | null;
            architecture: string | null;
            primary_language: string | null;
            framework: string | null;
            framework_version: string | null;
        } | null;
        technologies: Array<{
            name: string;
            category: string;
            version: string | null;
            confidence: string | number | null;
        }>;
        statistics: Record<string, number>;
        async: Array<{ id: number; name: string; kind: string; role: string }>;
        issues: Array<{
            id: number;
            severity: string;
            title: string;
            source_path: string | null;
            start_line: number | null;
        }>;
    };
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
    database,
    overview,
}: Props) {
    const { locale, t } = useTranslations();

    return (
        <>
            <Head title={project.name} />

            <div className="flex h-full min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex min-w-0 flex-col justify-between gap-4 sm:flex-row sm:items-start">
                    <div className="min-w-0 space-y-2">
                        <div className="flex min-w-0 flex-wrap items-center gap-3">
                            <h1 className="min-w-0 text-2xl font-semibold tracking-tight break-words">
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
                            <p className="max-w-3xl text-sm break-words text-muted-foreground">
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

                    <div className="flex shrink-0 flex-wrap gap-2">
                        <Button variant="outline" asChild>
                            <Link href={show(project.id).url + '/ask'}>
                                {t('ask.title')}
                            </Link>
                        </Button>
                        <Button variant="outline" asChild>
                            <Link
                                href={
                                    show(project.id).url + '/research/datasets'
                                }
                            >
                                {t('research.title')}
                            </Link>
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href={index()}>
                                {t('projects.show.back')}
                            </Link>
                        </Button>
                    </div>
                </div>

                <Card>
                    <CardContent className="min-w-0 space-y-4 pt-6">
                        <div className="flex min-w-0 flex-wrap items-start justify-between gap-3">
                            <div className="min-w-0 space-y-1">
                                <h2 className="font-medium">
                                    {t('projects.analysis.code_analysis')}
                                </h2>
                                <p className="max-w-3xl text-sm text-muted-foreground">
                                    {t(
                                        'projects.analysis.overview_description',
                                    )}
                                </p>
                            </div>
                            <Badge variant="secondary">
                                {t('projects.analysis.deterministic_badge')}
                            </Badge>
                        </div>
                        {overview.revision === null ? (
                            <p className="text-sm text-muted-foreground">
                                {t('projects.scan.not_scanned')}
                            </p>
                        ) : (
                            <>
                                <div className="grid min-w-0 gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                                    <div className="min-w-0">
                                        <div className="text-muted-foreground">
                                            {t('projects.analysis.revision')}
                                        </div>
                                        <div className="font-mono text-xs break-all">
                                            {overview.revision.identifier}
                                        </div>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {t(
                                                'projects.analysis.revision_term',
                                            )}
                                        </p>
                                    </div>
                                    <div className="min-w-0">
                                        <div className="text-muted-foreground">
                                            {t(
                                                'projects.analysis.analysis_status',
                                            )}
                                        </div>
                                        <div className="break-words">
                                            {overview.last_analysis
                                                ? t(
                                                      'projects.scan.status.' +
                                                          overview.last_analysis
                                                              .status,
                                                  )
                                                : t(
                                                      'projects.analysis.not_started',
                                                  )}
                                        </div>
                                    </div>
                                    <div className="min-w-0">
                                        <div className="text-muted-foreground">
                                            {t('projects.analysis.source')}
                                        </div>
                                        <div className="break-all">
                                            {project.source?.display_path ??
                                                '—'}
                                        </div>
                                    </div>
                                    <div className="min-w-0">
                                        <div className="text-muted-foreground">
                                            {t('projects.analysis.branch')}
                                        </div>
                                        <div className="break-words">
                                            {overview.revision.branch ?? '—'}
                                        </div>
                                    </div>
                                </div>
                                {overview.profile?.summary && (
                                    <div className="min-w-0 rounded-md border p-3">
                                        <h3 className="font-medium">
                                            {t('projects.analysis.profile')}
                                        </h3>
                                        <p className="mt-1 text-sm break-words">
                                            {overview.profile.summary}
                                        </p>
                                        <p className="mt-2 text-xs text-muted-foreground">
                                            {t(
                                                'projects.analysis.profile_description',
                                            )}
                                        </p>
                                    </div>
                                )}
                                <div>
                                    <h3 className="mb-2 text-sm font-medium">
                                        {t('projects.analysis.statistics')}
                                    </h3>
                                    <div className="grid min-w-0 grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-5">
                                        {Object.entries(
                                            overview.statistics,
                                        ).map(([label, value]) => (
                                            <div
                                                key={label}
                                                className="min-w-0 rounded-md border p-3"
                                            >
                                                <div className="text-xl font-semibold">
                                                    {value}
                                                </div>
                                                <div className="text-xs break-words text-muted-foreground capitalize">
                                                    {label.replaceAll('_', ' ')}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                                <div className="min-w-0 space-y-2">
                                    <h3 className="font-medium">
                                        {t('projects.analysis.technologies')}
                                    </h3>
                                    <p className="text-sm text-muted-foreground">
                                        {t(
                                            'projects.analysis.technologies_description',
                                        )}
                                    </p>
                                    <div className="flex flex-wrap gap-3">
                                        {overview.technologies.map(
                                            (technology) => (
                                                <div
                                                    key={`${technology.category}-${technology.name}`}
                                                    className="min-w-0 space-y-1"
                                                >
                                                    <Badge
                                                        variant="secondary"
                                                        className="max-w-full break-words"
                                                    >
                                                        {technology.name}
                                                        {technology.version
                                                            ? ` · ${technology.version}`
                                                            : ''}
                                                    </Badge>
                                                    <p className="text-xs break-words text-muted-foreground">
                                                        {technology.category}
                                                    </p>
                                                </div>
                                            ),
                                        )}
                                    </div>
                                </div>
                            </>
                        )}
                    </CardContent>
                </Card>

                <div className="grid min-w-0 gap-6 lg:grid-cols-2">
                    <Card className="min-w-0">
                        <CardContent className="min-w-0 space-y-3 pt-6">
                            <div>
                                <h2 className="font-medium">
                                    {t('projects.analysis.async_title')}
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    {t('projects.analysis.async_description')}
                                </p>
                            </div>
                            {overview.async.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    {t('projects.analysis.no_jobs')}
                                </p>
                            ) : (
                                overview.async.map((item) => (
                                    <div
                                        key={`${item.id}-${item.role}`}
                                        className="flex min-w-0 flex-wrap items-start justify-between gap-2 text-sm"
                                    >
                                        <span className="min-w-0 font-mono break-all">
                                            {item.name}
                                        </span>
                                        <Badge
                                            className="shrink-0"
                                            variant="outline"
                                        >
                                            {item.role}
                                        </Badge>
                                    </div>
                                ))
                            )}
                        </CardContent>
                    </Card>
                    <Card className="min-w-0">
                        <CardContent className="min-w-0 space-y-3 pt-6">
                            <div>
                                <h2 className="font-medium">
                                    {t('projects.analysis.issues_title')}
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    {t('projects.analysis.issues_description')}
                                </p>
                            </div>
                            {overview.issues.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    {t('projects.analysis.no_issues')}
                                </p>
                            ) : (
                                overview.issues.map((issue) => (
                                    <div
                                        key={issue.id}
                                        className="min-w-0 space-y-1 text-sm"
                                    >
                                        <div className="flex min-w-0 flex-wrap gap-2">
                                            <Badge variant="outline">
                                                {issue.severity}
                                            </Badge>
                                            <span className="min-w-0 break-words">
                                                {issue.title}
                                            </span>
                                        </div>
                                        <div className="font-mono text-xs break-all text-muted-foreground">
                                            {issue.source_path ?? 'project'}
                                            {issue.start_line
                                                ? `:${issue.start_line}`
                                                : ''}
                                        </div>
                                    </div>
                                ))
                            )}
                        </CardContent>
                    </Card>
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
                    <CardContent className="min-w-0 space-y-4 pt-6">
                        <div>
                            <h2 className="font-medium">
                                {t('projects.analysis.database_title')}
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                {t('projects.analysis.database_description')}
                            </p>
                        </div>
                        {database.tables.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                {t('projects.analysis.database_empty')}
                            </p>
                        ) : (
                            database.tables.map((table) => {
                                const columns = database.columns.filter(
                                    (column) =>
                                        column.database_table_id === table.id,
                                );
                                const indexes = database.indexes.filter(
                                    (index) =>
                                        index.database_table_id === table.id,
                                );
                                const foreignKeys =
                                    database.foreign_keys.filter(
                                        (foreignKey) =>
                                            foreignKey.database_table_id ===
                                            table.id,
                                    );

                                return (
                                    <div
                                        key={table.id}
                                        className="min-w-0 space-y-2 rounded-md border p-3"
                                    >
                                        <h3 className="font-mono text-sm font-medium break-all">
                                            {table.schema_name}.{table.name}
                                        </h3>
                                        <div className="grid min-w-0 gap-2 text-sm md:grid-cols-2">
                                            <div className="min-w-0">
                                                <div className="font-medium">
                                                    {t(
                                                        'projects.analysis.columns',
                                                    )}
                                                </div>
                                                {columns.length === 0 ? (
                                                    <span className="text-muted-foreground">
                                                        —
                                                    </span>
                                                ) : (
                                                    columns.map((column) => (
                                                        <div
                                                            key={column.id}
                                                            className="font-mono text-xs break-all"
                                                        >
                                                            {column.name}:{' '}
                                                            {column.data_type}
                                                            {column.is_nullable
                                                                ? ' nullable'
                                                                : ''}
                                                            {column.default_value !==
                                                            null
                                                                ? ` default ${column.default_value}`
                                                                : ''}
                                                        </div>
                                                    ))
                                                )}
                                            </div>
                                            <div className="min-w-0">
                                                <div className="font-medium">
                                                    {t(
                                                        'projects.analysis.indexes',
                                                    )}
                                                </div>
                                                {indexes.length === 0 ? (
                                                    <span className="text-muted-foreground">
                                                        —
                                                    </span>
                                                ) : (
                                                    indexes.map((index) => (
                                                        <div
                                                            key={index.id}
                                                            className="text-xs break-all"
                                                        >
                                                            {index.name} (
                                                            {index.columns.join(
                                                                ', ',
                                                            )}
                                                            )
                                                            {index.is_primary
                                                                ? ' primary'
                                                                : index.is_unique
                                                                  ? ' unique'
                                                                  : ''}
                                                        </div>
                                                    ))
                                                )}
                                                <div className="mt-2 font-medium">
                                                    {t(
                                                        'projects.analysis.foreign_keys',
                                                    )}
                                                </div>
                                                {foreignKeys.length === 0 ? (
                                                    <span className="text-muted-foreground">
                                                        —
                                                    </span>
                                                ) : (
                                                    foreignKeys.map(
                                                        (foreignKey) => (
                                                            <div
                                                                key={
                                                                    foreignKey.id
                                                                }
                                                                className="text-xs break-all"
                                                            >
                                                                {
                                                                    foreignKey.name
                                                                }
                                                                :{' '}
                                                                {foreignKey.columns
                                                                    .map(
                                                                        (
                                                                            column,
                                                                        ) =>
                                                                            `${column.column} → ${foreignKey.referenced_table_name}.${column.referenced_column}`,
                                                                    )
                                                                    .join(', ')}
                                                            </div>
                                                        ),
                                                    )
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                );
                            })
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <div>
                            <h2 className="font-medium">
                                {t('projects.routes.title')}
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                {t(
                                    'projects.analysis.routes_section_description',
                                )}
                            </p>
                        </div>

                        {routes.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                {t('projects.routes.empty')}
                            </p>
                        ) : (
                            <div className="overflow-x-auto rounded-md border">
                                <table className="w-full min-w-[64rem] text-left text-sm">
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
                                                <td className="px-3 py-2 align-top font-mono text-xs break-all">
                                                    {route.uri}
                                                </td>
                                                <td className="px-3 py-2 align-top break-all">
                                                    {route.name ?? '—'}
                                                </td>
                                                <td className="px-3 py-2 align-top font-mono text-xs break-all">
                                                    {route.controller ?? '—'}
                                                </td>
                                                <td className="px-3 py-2 align-top break-all">
                                                    {route.middleware.length > 0
                                                        ? route.middleware.join(
                                                              ', ',
                                                          )
                                                        : '—'}
                                                </td>
                                                <td className="px-3 py-2 align-top font-mono text-xs break-all">
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
