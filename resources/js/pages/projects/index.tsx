import { Head, Link } from '@inertiajs/react';
import { ArrowRight, FolderKanban, Plus } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { create, index, show } from '@/routes/projects';
import type { ProjectStatus, ProjectSummary } from '@/types';

type Props = {
    projects: ProjectSummary[];
};

const statusLabels: Record<ProjectStatus, string> = {
    connected: 'Connected',
    not_connected: 'No source',
};

function formatDate(date: string): string {
    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
    }).format(new Date(date));
}

export default function ProjectsIndex({ projects }: Props) {
    return (
        <>
            <Head title="Projects" />

            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto p-4 md:p-6">
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Projects
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Browse and manage your CodeAtlas projects.
                        </p>
                    </div>

                    <Button asChild>
                        <Link href={create()}>
                            <Plus />
                            New project
                        </Link>
                    </Button>
                </div>

                {projects.length === 0 ? (
                    <Card className="items-center py-12 text-center">
                        <CardContent className="flex flex-col items-center gap-4">
                            <div className="rounded-full bg-muted p-3">
                                <FolderKanban className="size-6 text-muted-foreground" />
                            </div>
                            <div className="space-y-1">
                                <h2 className="font-medium">No projects yet</h2>
                                <p className="text-sm text-muted-foreground">
                                    Create your first project to get started.
                                </p>
                            </div>
                            <Button asChild>
                                <Link href={create()}>Create project</Link>
                            </Button>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {projects.map((project) => (
                            <Card key={project.id}>
                                <CardHeader>
                                    <div className="flex items-start justify-between gap-4">
                                        <CardTitle>{project.name}</CardTitle>
                                        <Badge
                                            variant={
                                                project.status === 'connected'
                                                    ? 'default'
                                                    : 'secondary'
                                            }
                                        >
                                            {statusLabels[project.status]}
                                        </Badge>
                                    </div>
                                    {project.description && (
                                        <CardDescription>
                                            {project.description}
                                        </CardDescription>
                                    )}
                                </CardHeader>
                                <CardContent className="mt-auto">
                                    <time
                                        dateTime={project.created_at}
                                        className="text-sm text-muted-foreground"
                                    >
                                        Created {formatDate(project.created_at)}
                                    </time>
                                </CardContent>
                                <CardFooter>
                                    <Button variant="outline" asChild>
                                        <Link href={show(project.id)}>
                                            Open project
                                            <ArrowRight />
                                        </Link>
                                    </Button>
                                </CardFooter>
                            </Card>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

ProjectsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Projects',
            href: index(),
        },
    ],
};
