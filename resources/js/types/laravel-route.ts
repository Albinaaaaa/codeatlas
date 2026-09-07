export type LaravelRouteSummary = {
    id: number;
    method: string;
    uri: string;
    name: string | null;
    controller: string | null;
    middleware: string[];
    source_path: string;
    start_line: number | null;
    end_line: number | null;
};
