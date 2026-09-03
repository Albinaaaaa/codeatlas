export type RepositoryScanStatus =
    'pending' | 'running' | 'completed' | 'failed';

export type RepositoryScanSummary = {
    status: RepositoryScanStatus;
    revision: string;
    files_count: number;
    issues_count: number;
    completed_at: string | null;
    failure_reason: string | null;
};

export type LocalProjectSourceSummary = {
    id: number;
    type: 'local_directory';
    display_path: string | null;
    status: 'available' | 'unavailable';
    scan: RepositoryScanSummary | null;
};

export type ProjectSourceEndpoints = {
    directories: string;
    local: string;
    scan: string;
};
