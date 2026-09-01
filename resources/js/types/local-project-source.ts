export type LocalProjectSourceSummary = {
    id: number;
    type: 'local_directory';
    display_path: string | null;
    status: 'available' | 'unavailable';
};

export type ProjectSourceEndpoints = {
    directories: string;
    local: string;
};
