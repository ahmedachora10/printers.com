import { Badge } from '@/components/ui/badge';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type CatalogueBranchOption } from '@/types/catalogue';
import { type ImportScope } from '@/types/import';

/**
 * تاسك 47: every catalogue row — category, subcategory, price — belongs either
 * to one branch or to the shared catalogue everyone inherits. The three admin
 * screens answer the same questions about that ownership, so they answer them
 * from here rather than each in its own words.
 */
export interface CatalogueScope {
    /** Super admin only: the branches they may author rows for. Null = the user is pinned to one branch. */
    branches: CatalogueBranchOption[] | null;
    /** Branch admin only: the branch whose rows they own. */
    ownBranchId: number | null;
    /** Branch admin only: that branch's name, so the import dialog can name the destination. */
    ownBranchName?: string | null;
}

/** Sentinel for the shared catalogue — a Select cannot carry a null value. */
export const GENERAL = 'general';

/** The super admin owns every row; everyone else owns their branch's alone. */
export function canEditRow(scope: CatalogueScope, branchId: number | null): boolean {
    return scope.branches !== null || branchId === scope.ownBranchId;
}

/** Which branch a newly created row should default to, given the filter on screen. */
export function defaultBranchFor(scope: CatalogueScope, branchFilter: string | undefined): number | null {
    if (scope.branches === null) {
        return scope.ownBranchId;
    }

    return branchFilter && branchFilter !== GENERAL ? Number(branchFilter) : null;
}

/** The owner of one row, as a table cell. */
export function ScopeBadge({ branchId, branchName }: { branchId: number | null; branchName?: string | null }) {
    if (branchId === null) {
        return (
            <Badge variant="outline" className="border-border bg-muted/60 text-muted-foreground">
                عام — كل الفروع
            </Badge>
        );
    }

    return (
        <Badge variant="outline" className="border-primary/30 bg-primary/10 text-primary">
            {branchName ?? 'فرع'}
        </Badge>
    );
}

/**
 * The branch filter for the FilterBar, or nothing at all for a user whose rows
 * are pinned server-side. Spread into the `filters` array.
 */
export function branchFilterOptions(scope: CatalogueScope) {
    if (!scope.branches) {
        return [];
    }

    return [
        {
            key: 'branch',
            placeholder: 'الفرع',
            options: [
                { value: GENERAL, label: 'عام — كل الفروع' },
                ...scope.branches.map((b) => ({ value: String(b.id), label: b.name })),
            ],
        },
    ];
}

/**
 * Owner picker inside a create form. Shown to the super admin only, and only
 * while creating: a row never changes owner, and the branch admin's owner is
 * pinned on the server either way.
 */
export function BranchScopeField({
    id,
    branches,
    value,
    onChange,
    error,
    hint,
}: {
    id: string;
    branches: CatalogueBranchOption[] | null;
    value: number | null;
    onChange: (branchId: number | null) => void;
    error?: string;
    hint: string;
}) {
    if (!branches) {
        return null;
    }

    return (
        <div className="space-y-1">
            <Label htmlFor={id}>النطاق</Label>
            <Select
                value={value === null ? GENERAL : String(value)}
                onValueChange={(next) => onChange(next === GENERAL ? null : Number(next))}
            >
                <SelectTrigger id={id}>
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={GENERAL}>عام — كل الفروع</SelectItem>
                    {branches.map((branch) => (
                        <SelectItem key={branch.id} value={String(branch.id)}>
                            {branch.name}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            <p className="text-xs text-muted-foreground">{hint}</p>
            {error && <p className="text-xs text-destructive">{error}</p>}
        </div>
    );
}

/** The one-line explanation of what the user is allowed to write on this screen. */
export function scopeHint(scope: CatalogueScope, general: string, own: string): string {
    return scope.branches ? general : own;
}

/**
 * The import dialog's destination field, in catalogue terms: the super admin
 * picks, everyone else is told. Same rule as BranchScopeField — a picker for a
 * user whose branch the server pins would be a lie.
 */
export function catalogueImportScope(
    scope: CatalogueScope,
    value: string,
    onChange: (value: string) => void,
): ImportScope {
    return {
        options: scope.branches
            ? [
                  { value: GENERAL, label: 'عام — كل الفروع' },
                  ...scope.branches.map((branch) => ({ value: String(branch.id), label: branch.name })),
              ]
            : null,
        value: value || GENERAL,
        onChange,
        pinnedLabel: scope.ownBranchName ?? 'فرعك',
        hint: 'كل صفوف الملف ستُنسب إلى هذه الجهة — الفئات والخدمات والأسعار الجديدة.',
    };
}
