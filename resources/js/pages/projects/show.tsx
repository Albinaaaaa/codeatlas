import { Head, Link } from '@inertiajs/react';
import { FolderOpen } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { index, show } from '@/routes/projects';
import type { ProjectSummary } from '@/types';

type Props = {
    project: ProjectSummary;
};

function formatDate(date: string): string {
    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
    }).format(new Date(date));
}

export default function ProjectsShow({ project }: Props) {
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
                                {project.status === 'connected'
                                    ? 'Connected'
                                    : 'No source'}
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
                            Created {formatDate(project.created_at)}
                        </time>
                    </div>

                    <Button variant="outline" asChild>
                        <Link href={index()}>Back to projects</Link>
                    </Button>
                </div>

                <Card className="min-h-64 items-center justify-center text-center">
                    <CardContent className="flex flex-col items-center gap-3">
                        <div className="rounded-full bg-muted p-3">
                            <FolderOpen className="size-6 text-muted-foreground" />
                        </div>
                        <div className="space-y-1">
                            <h2 className="font-medium">
                                No source connected yet.
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                Source connections will be available in a future
                                step.
                            </p>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

ProjectsShow.layout = ({ project }: Props) => ({
    breadcrumbs: [
        {
            title: 'Projects',
            href: index(),
        },
        {
            title: project.name,
            href: show(project.id),
        },
    ],
});
