export type ResearchProject = { id: number; name: string };
export type ResearchRevision = { id: number; identifier: string };
export type Evidence = { path: string; start_line: number; end_line: number };
export type ResearchQuestion = {
    question_id: string;
    category: string;
    question: string;
    reference_answer: string;
    required_facts: string[];
    evidence: Evidence[];
    verification: {
        verified: boolean;
        verified_by?: string;
        verified_at?: string;
    };
};
export type ValidationReport = {
    total: number;
    valid: number;
    invalid: number;
    dataset_sha256: string;
    errors: {
        line: number;
        question_id: string | null;
        field: string;
        message: string;
    }[];
};
export type ResearchDataset = {
    id: number;
    dataset_id: string;
    dataset_version: string;
    split: string;
    status: 'draft' | 'frozen';
    lock_version: number;
    original_sha256: string | null;
    frozen_sha256: string | null;
    frozen_at: string | null;
    revision: ResearchRevision;
    questions: ResearchQuestion[];
    validation_report: ValidationReport | null;
};
