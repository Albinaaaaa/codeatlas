import { Card, CardContent } from '@/components/ui/card';
import { useTranslations } from '@/hooks/use-translations';
import type { LaravelModelSummary } from '@/types/laravel-model';

export default function ModelsPanel({
    models,
}: {
    models: LaravelModelSummary[];
}) {
    const { t } = useTranslations();

    return (
        <Card>
            <CardContent className="space-y-4 pt-6">
                <div>
                    <h2 className="font-medium">
                        {t('projects.models.title')}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {t('projects.models.description')}
                    </p>
                </div>
                {models.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('projects.models.empty')}
                    </p>
                ) : (
                    models.map((model) => (
                        <details
                            key={model.id}
                            className="rounded-md border p-4"
                        >
                            <summary className="cursor-pointer font-mono text-sm break-all">
                                {model.class}
                                <span className="ml-3 text-muted-foreground">
                                    {model.table_name ??
                                        t('projects.models.unresolved')}
                                </span>
                            </summary>
                            <div className="mt-4 space-y-4">
                                <p className="font-mono text-xs text-muted-foreground">
                                    {model.source_path}:{model.start_line}–
                                    {model.end_line}
                                </p>
                                {Object.keys(model.configuration).length >
                                    0 && (
                                    <dl className="grid gap-2 text-sm sm:grid-cols-2">
                                        {Object.entries(
                                            model.configuration,
                                        ).map(([key, value]) => (
                                            <div key={key}>
                                                <dt className="font-medium">
                                                    {key}
                                                </dt>
                                                <dd className="font-mono text-xs break-all text-muted-foreground">
                                                    {value === null
                                                        ? t(
                                                              'projects.models.unresolved',
                                                          )
                                                        : JSON.stringify(value)}
                                                </dd>
                                            </div>
                                        ))}
                                    </dl>
                                )}
                                <h3 className="text-sm font-medium">
                                    {t('projects.models.relationships')}
                                </h3>
                                {model.relations.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        {t('projects.models.no_relations')}
                                    </p>
                                ) : (
                                    <div className="overflow-x-auto rounded-md border">
                                        <table className="w-full text-left text-sm">
                                            <thead className="border-b bg-muted/50 text-xs text-muted-foreground">
                                                <tr>
                                                    {[
                                                        'name',
                                                        'type',
                                                        'related',
                                                        'keys',
                                                        'source',
                                                    ].map((column) => (
                                                        <th
                                                            key={column}
                                                            className="px-3 py-2 font-medium"
                                                        >
                                                            {t(
                                                                'projects.models.' +
                                                                    column,
                                                            )}
                                                        </th>
                                                    ))}
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y">
                                                {model.relations.map(
                                                    (relation) => (
                                                        <tr key={relation.id}>
                                                            <td className="px-3 py-2 align-top">
                                                                {relation.name}
                                                            </td>
                                                            <td className="px-3 py-2 align-top font-mono text-xs">
                                                                {
                                                                    relation.relation_type
                                                                }
                                                            </td>
                                                            <td className="px-3 py-2 align-top font-mono text-xs">
                                                                {relation.related_model ===
                                                                '*'
                                                                    ? t(
                                                                          'projects.models.polymorphic',
                                                                      )
                                                                    : relation.related_model}
                                                            </td>
                                                            <td className="px-3 py-2 align-top font-mono text-xs">
                                                                {Object.entries(
                                                                    relation.arguments,
                                                                )
                                                                    .filter(
                                                                        ([
                                                                            key,
                                                                        ]) =>
                                                                            key !==
                                                                            'related',
                                                                    )
                                                                    .map(
                                                                        ([
                                                                            key,
                                                                            value,
                                                                        ]) => (
                                                                            <div
                                                                                key={
                                                                                    key
                                                                                }
                                                                            >
                                                                                {
                                                                                    key
                                                                                }

                                                                                :{' '}
                                                                                {JSON.stringify(
                                                                                    value,
                                                                                )}
                                                                            </div>
                                                                        ),
                                                                    )}
                                                            </td>
                                                            <td className="px-3 py-2 align-top font-mono text-xs whitespace-nowrap">
                                                                {
                                                                    relation.source_path
                                                                }
                                                                :
                                                                {
                                                                    relation.start_line
                                                                }
                                                                –
                                                                {
                                                                    relation.end_line
                                                                }
                                                            </td>
                                                        </tr>
                                                    ),
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </div>
                        </details>
                    ))
                )}
            </CardContent>
        </Card>
    );
}
