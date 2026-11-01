import { useState } from 'react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslations } from '@/hooks/use-translations';

type Source = {
    path: string;
    start_line: number;
    end_line: number;
    lines: string[];
};

export default function EvidenceViewer({
    url,
    disabled,
}: {
    url: string;
    disabled: boolean;
}) {
    const { t } = useTranslations();
    const [source, setSource] = useState<Source | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(false);

    async function load() {
        setLoading(true);
        setError(false);
        setSource(null);

        try {
            const response = await fetch(url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
            });

            if (!response.ok) {
throw new Error('Evidence request failed');
}

            const data: unknown = await response.json();

            if (
                typeof data !== 'object' ||
                data === null ||
                !('lines' in data) ||
                !Array.isArray(data.lines) ||
                !data.lines.every((line) => typeof line === 'string') ||
                !('path' in data) ||
                typeof data.path !== 'string' ||
                !('start_line' in data) ||
                typeof data.start_line !== 'number' ||
                !('end_line' in data) ||
                typeof data.end_line !== 'number'
            ) {
throw new Error('Invalid evidence response');
}

            setSource({
                path: data.path,
                start_line: data.start_line,
                end_line: data.end_line,
                lines: data.lines,
            });
        } catch {
            setError(true);
        } finally {
            setLoading(false);
        }
    }

    return (
        <div className="space-y-3">
            <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={disabled || loading}
                onClick={load}
            >
                {loading && <Spinner />}
                {t(loading ? 'research.loading' : 'research.view_evidence')}
            </Button>
            {!disabled && error && (
                <Alert variant="destructive">
                    <AlertDescription>
                        {t('research.evidence_error')}
                    </AlertDescription>
                </Alert>
            )}
            {!disabled && source && (
                <div className="overflow-x-auto rounded-md border bg-muted/30 p-3">
                    <p className="mb-2 break-all font-mono text-xs text-muted-foreground">
                        {source.path}:{source.start_line}–{source.end_line}
                    </p>
                    <pre className="text-xs leading-6">
                        {source.lines.map((line, offset) => (
                            <div key={offset}>
                                <span className="mr-4 inline-block min-w-10 text-right text-muted-foreground select-none">
                                    {source.start_line + offset}
                                </span>
                                <code>{line}</code>
                            </div>
                        ))}
                    </pre>
                </div>
            )}
        </div>
    );
}
