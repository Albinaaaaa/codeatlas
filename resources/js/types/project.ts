export type ProjectStatus = 'connected' | 'not_connected';

export type ProjectSummary = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    status: ProjectStatus;
    created_at: string;
};
