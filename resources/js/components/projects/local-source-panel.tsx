import { Form } from '@inertiajs/react';
import {
    ChevronUp,
    Folder,
    FolderInput,
    FolderOpen,
    HardDrive,
    LoaderCircle,
    ScanSearch,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import { useTranslations } from '@/hooks/use-translations';
import type {
    LocalProjectSourceSummary,
    ProjectSourceEndpoints,
    RepositoryScanSummary,
} from '@/types/local-project-source';

type DirectoryEntry = {
    name: string;
    path: string;
};

type DirectoryListing = {
    current_path: string;
    current_display_path: string;
    parent_path: string | null;
    directories: DirectoryEntry[];
};

type Props = {
    configured: boolean;
    endpoints: ProjectSourceEndpoints;
    source: LocalProjectSourceSummary | null;
};

export default function LocalSourcePanel({
    configured,
    endpoints,
    source,
}: Props) {
    const { t } = useTranslations();
    const [connectOpen, setConnectOpen] = useState(false);

    const connectButton = (
        <Button type="button" disabled={!configured}>
            <FolderInput />
            {source
                ? t('projects.sources.replace')
                : t('projects.sources.connect')}
        </Button>
    );

    if (!source && !configured) {
        return (
            <Card className="min-h-64 items-center justify-center text-center">
                <CardContent className="flex max-w-xl flex-col items-center gap-4">
                    <div className="rounded-full bg-muted p-3">
                        <FolderOpen className="size-6 text-muted-foreground" />
                    </div>
                    <div className="space-y-1">
                        <h2 className="font-medium">
                            {t('projects.sources.configuration.title')}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {t('projects.sources.configuration.description')}
                        </p>
                    </div>
                </CardContent>
            </Card>
        );
    }

    return (
        <>
            {source ? (
                <Card>
                    <CardHeader>
                        <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                            <div className="space-y-1">
                                <CardTitle>
                                    {t('projects.sources.title')}
                                </CardTitle>
                                <p className="text-sm text-muted-foreground">
                                    {t('projects.sources.description')}
                                </p>
                            </div>
                            <Badge
                                variant={
                                    source.status === 'available'
                                        ? 'default'
                                        : 'secondary'
                                }
                            >
                                {t(`projects.sources.status.${source.status}`)}
                            </Badge>
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        <dl className="grid gap-4 sm:grid-cols-[10rem_1fr]">
                            <dt className="text-sm font-medium">
                                {t('projects.sources.type_label')}
                            </dt>
                            <dd className="text-sm text-muted-foreground">
                                {t('projects.sources.local_directory')}
                            </dd>
                            <dt className="text-sm font-medium">
                                {t('projects.sources.path_label')}
                            </dt>
                            <dd className="min-w-0 font-mono text-sm break-all text-muted-foreground">
                                {source.display_path ??
                                    t('projects.sources.path_unavailable')}
                            </dd>
                        </dl>

                        {!configured && (
                            <p className="rounded-md border bg-muted/40 p-3 text-sm text-muted-foreground">
                                {t(
                                    'projects.sources.configuration.description',
                                )}
                            </p>
                        )}

                        <RepositoryScanPanel
                            endpoint={endpoints.scan}
                            scan={source.scan}
                            sourceAvailable={source.status === 'available'}
                        />

                        <div className="flex flex-wrap gap-3">
                            <Dialog
                                open={configured && connectOpen}
                                onOpenChange={(open) =>
                                    configured && setConnectOpen(open)
                                }
                            >
                                <DialogTrigger asChild>
                                    {connectButton}
                                </DialogTrigger>
                                <LocalDirectoryForm
                                    endpoints={endpoints}
                                    replacing
                                    onSuccess={() => setConnectOpen(false)}
                                />
                            </Dialog>
                            <RemoveSourceDialog endpoint={endpoints.local} />
                        </div>
                    </CardContent>
                </Card>
            ) : (
                <Card className="min-h-64 items-center justify-center text-center">
                    <CardContent className="flex flex-col items-center gap-4">
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
                        <Dialog
                            open={connectOpen}
                            onOpenChange={setConnectOpen}
                        >
                            <DialogTrigger asChild>
                                {connectButton}
                            </DialogTrigger>
                            <LocalDirectoryForm
                                endpoints={endpoints}
                                onSuccess={() => setConnectOpen(false)}
                            />
                        </Dialog>
                    </CardContent>
                </Card>
            )}
        </>
    );
}

function RepositoryScanPanel({
    endpoint,
    scan,
    sourceAvailable,
}: {
    endpoint: string;
    scan: RepositoryScanSummary | null;
    sourceAvailable: boolean;
}) {
    const { locale, t } = useTranslations();
    const completedAt = scan?.completed_at
        ? new Intl.DateTimeFormat(locale === 'uk' ? 'uk-UA' : 'en-US', {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(scan.completed_at))
        : null;

    return (
        <div className="space-y-4 rounded-lg border bg-muted/20 p-4">
            <div className="flex flex-col justify-between gap-2 sm:flex-row sm:items-start">
                <div className="space-y-1">
                    <h3 className="text-sm font-medium">
                        {t('projects.scan.title')}
                    </h3>
                    <p className="text-sm text-muted-foreground">
                        {t('projects.scan.description')}
                    </p>
                </div>
                {scan && (
                    <Badge
                        variant={
                            scan.status === 'completed'
                                ? 'default'
                                : 'secondary'
                        }
                    >
                        {t(`projects.scan.status.${scan.status}`)}
                    </Badge>
                )}
            </div>

            {scan ? (
                <dl className="grid gap-3 text-sm sm:grid-cols-[10rem_1fr]">
                    <dt className="font-medium">
                        {t('projects.scan.revision')}
                    </dt>
                    <dd className="min-w-0 font-mono break-all text-muted-foreground">
                        {scan.revision}
                    </dd>
                    <dt className="font-medium">{t('projects.scan.files')}</dt>
                    <dd className="text-muted-foreground">
                        {scan.files_count}
                    </dd>
                    <dt className="font-medium">{t('projects.scan.issues')}</dt>
                    <dd className="text-muted-foreground">
                        {scan.issues_count}
                    </dd>
                    {completedAt && (
                        <>
                            <dt className="font-medium">
                                {t('projects.scan.completed_at')}
                            </dt>
                            <dd className="text-muted-foreground">
                                {completedAt}
                            </dd>
                        </>
                    )}
                </dl>
            ) : (
                <p className="text-sm text-muted-foreground">
                    {t('projects.scan.not_scanned')}
                </p>
            )}

            <Form
                action={endpoint}
                method="post"
                options={{ preserveScroll: true }}
            >
                {({ processing, errors }) => (
                    <div className="space-y-2">
                        <Button
                            type="submit"
                            disabled={!sourceAvailable || processing}
                        >
                            {processing ? (
                                <LoaderCircle className="animate-spin" />
                            ) : (
                                <ScanSearch />
                            )}
                            {processing
                                ? t('projects.scan.scanning')
                                : t('projects.scan.action')}
                        </Button>
                        <InputError message={errors.scan} />
                    </div>
                )}
            </Form>
        </div>
    );
}

function LocalDirectoryForm({
    endpoints,
    replacing = false,
    onSuccess,
}: {
    endpoints: ProjectSourceEndpoints;
    replacing?: boolean;
    onSuccess: () => void;
}) {
    const { t } = useTranslations();
    const [requestedPath, setRequestedPath] = useState<string>();
    const [listing, setListing] = useState<DirectoryListing | null>(null);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState<string | null>(null);

    const browseDirectory = (path?: string) => {
        setLoading(true);
        setLoadError(null);
        setRequestedPath(path ?? '');
    };

    useEffect(() => {
        const controller = new AbortController();
        const url = new URL(endpoints.directories, window.location.origin);

        if (requestedPath) {
            url.searchParams.set('path', requestedPath);
        }

        void fetch(url, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then(async (response) => {
                const payload = (await response.json()) as
                    DirectoryListing | { message?: string };

                if (!response.ok) {
                    throw new Error(
                        'message' in payload && payload.message
                            ? payload.message
                            : '',
                    );
                }

                setListing(payload as DirectoryListing);
            })
            .catch((error: unknown) => {
                if (
                    error instanceof DOMException &&
                    error.name === 'AbortError'
                ) {
                    return;
                }

                setListing(null);
                setLoadError(error instanceof Error ? error.message : '');
            })
            .finally(() => {
                if (!controller.signal.aborted) {
                    setLoading(false);
                }
            });

        return () => controller.abort();
    }, [endpoints.directories, requestedPath]);

    return (
        <DialogContent className="sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>
                    {replacing
                        ? t('projects.sources.replace_title')
                        : t('projects.sources.connect_title')}
                </DialogTitle>
                <DialogDescription>
                    {t('projects.sources.connect_description')}
                </DialogDescription>
            </DialogHeader>

            <Form
                action={endpoints.local}
                method="put"
                options={{ preserveScroll: true }}
                onSuccess={onSuccess}
                className="space-y-5"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="flex items-start gap-3 rounded-lg border bg-muted/40 p-4">
                            <HardDrive className="mt-0.5 size-5 shrink-0" />
                            <div>
                                <p className="text-sm font-medium">
                                    {t('projects.sources.local_directory')}
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {t('projects.sources.local_hint')}
                                </p>
                            </div>
                        </div>

                        <div className="space-y-3">
                            <div>
                                <p className="text-sm font-medium">
                                    {t('projects.sources.browser.title')}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {t('projects.sources.browser.description')}
                                </p>
                            </div>

                            <div className="flex items-center gap-2">
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="outline"
                                    disabled={
                                        loading ||
                                        !listing ||
                                        listing.parent_path === null
                                    }
                                    aria-label={t(
                                        'projects.sources.browser.up',
                                    )}
                                    onClick={() =>
                                        browseDirectory(
                                            listing?.parent_path ?? undefined,
                                        )
                                    }
                                >
                                    <ChevronUp />
                                </Button>
                                <div className="min-w-0 flex-1 rounded-md border bg-muted/30 px-3 py-2 font-mono text-xs break-all">
                                    {listing
                                        ? listing.current_display_path ||
                                          t(
                                              'projects.sources.browser.workspace_root',
                                          )
                                        : t('projects.sources.browser.loading')}
                                </div>
                            </div>

                            <div className="max-h-72 overflow-y-auto rounded-md border">
                                {loading ? (
                                    <div className="flex items-center justify-center gap-2 p-8 text-sm text-muted-foreground">
                                        <LoaderCircle className="size-4 animate-spin" />
                                        {t('projects.sources.browser.loading')}
                                    </div>
                                ) : loadError !== null ? (
                                    <div className="space-y-3 p-6 text-center">
                                        <p className="text-sm text-destructive">
                                            {loadError ||
                                                t(
                                                    'projects.sources.browser.unavailable',
                                                )}
                                        </p>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={() => browseDirectory()}
                                        >
                                            {t(
                                                'projects.sources.browser.show_root',
                                            )}
                                        </Button>
                                    </div>
                                ) : listing?.directories.length ? (
                                    <div className="divide-y">
                                        {listing.directories.map(
                                            (directory) => (
                                                <button
                                                    key={directory.path}
                                                    type="button"
                                                    className="flex w-full items-center gap-3 px-4 py-3 text-left text-sm hover:bg-muted focus-visible:bg-muted focus-visible:outline-none"
                                                    onClick={() =>
                                                        browseDirectory(
                                                            directory.path,
                                                        )
                                                    }
                                                >
                                                    <Folder className="size-4 shrink-0 text-muted-foreground" />
                                                    <span className="truncate">
                                                        {directory.name}
                                                    </span>
                                                </button>
                                            ),
                                        )}
                                    </div>
                                ) : (
                                    <p className="p-8 text-center text-sm text-muted-foreground">
                                        {t('projects.sources.browser.empty')}
                                    </p>
                                )}
                            </div>

                            <input
                                type="hidden"
                                name="path"
                                value={listing?.current_path ?? ''}
                            />
                            <InputError message={errors.path} />
                        </div>

                        <DialogFooter>
                            <DialogClose asChild>
                                <Button type="button" variant="outline">
                                    {t('projects.sources.cancel')}
                                </Button>
                            </DialogClose>
                            <Button
                                type="submit"
                                disabled={
                                    processing ||
                                    loading ||
                                    listing === null ||
                                    listing.current_path === ''
                                }
                            >
                                {replacing
                                    ? t('projects.sources.save_replacement')
                                    : t('projects.sources.save')}
                            </Button>
                        </DialogFooter>
                    </>
                )}
            </Form>
        </DialogContent>
    );
}

function RemoveSourceDialog({ endpoint }: { endpoint: string }) {
    const { t } = useTranslations();

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="outline">
                    {t('projects.sources.remove')}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {t('projects.sources.remove_title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('projects.sources.remove_description')}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    action={endpoint}
                    method="delete"
                    options={{ preserveScroll: true }}
                >
                    {({ processing }) => (
                        <DialogFooter>
                            <DialogClose asChild>
                                <Button type="button" variant="outline">
                                    {t('projects.sources.cancel')}
                                </Button>
                            </DialogClose>
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={processing}
                            >
                                {t('projects.sources.confirm_remove')}
                            </Button>
                        </DialogFooter>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
