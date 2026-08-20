/** The report shape every Excel import endpoint answers with — see App\Support\Import\ImportReport. */
export type ImportAction = 'create' | 'update' | 'skip' | 'ok';

export interface ImportSummaryTile {
    key: string;
    label: string;
    value: number;
    tone: 'success' | 'info' | 'warning';
}

export interface ImportRowResult {
    row: number;
    label: string;
    action: ImportAction;
    reason: string | null;
}

export interface ImportReport {
    /** true while nothing has been written yet — the preview. */
    dryRun: boolean;
    totalRows: number;
    summary: ImportSummaryTile[];
    /** The head of the sheet, for the preview table. */
    rows: ImportRowResult[];
    skipped: ImportRowResult[];
    /** Preview only: names the parked file the commit will import. */
    token?: string;
    fileName?: string;
}

/** Owner picker inside the dialog: options, or a fixed label for a pinned user. */
export interface ImportScope {
    options: { value: string; label: string }[] | null;
    value: string;
    onChange: (value: string) => void;
    /** Shown instead of the picker when the user does not choose (branch admin). */
    pinnedLabel?: string;
    hint?: string;
}
