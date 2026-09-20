export type LaravelModelRelationSummary = {
    id: number;
    name: string;
    relation_type: string;
    related_model: string;
    related_laravel_model_id: number | null;
    foreign_key: string | null;
    local_key: string | null;
    pivot_table: string | null;
    source_path: string;
    start_line: number | null;
    end_line: number | null;
    arguments: Record<string, string | boolean | null>;
};

export type LaravelModelSummary = {
    id: number;
    code_symbol_id: number;
    class: string;
    table_name: string | null;
    connection: string | null;
    source_path: string;
    start_line: number | null;
    end_line: number | null;
    configuration: Record<string, unknown>;
    relations: LaravelModelRelationSummary[];
};
