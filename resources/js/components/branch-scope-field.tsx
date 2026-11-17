import InputError from '@/components/input-error';
import { FilterMultiSelect } from '@/components/reports/filter-fields';

/** فروع سجلٍّ مملوكٍ لفروع (تاسك 238): فارغة = عامة لكل الفروع. */
export interface BranchScoped {
    branches?: { id: number; name: string }[];
}

/** الفروع كما يقرؤها FilterMultiSelect: «3,7»، وفارغ = عامة. */
export const scopeValue = (record?: BranchScoped | null) => (record?.branches ?? []).map((b) => b.id).join(',');

/** «عامة» أو أسماء الفروع. */
export const scopeLabel = (record: BranchScoped) => (record.branches?.length ? record.branches.map((b) => b.name).join('، ') : 'عامة');

/** قيمة الحقل كـ branch_ids[] للخادم؛ بلا قائمة فروع (مدير الفرع) لا يُرسَل — الخادم يثبّته على فرعه. */
export const scopeIds = (value: string, branches?: unknown[] | null) => (branches ? (value ? value.split(',') : []) : undefined);

/** «النطاق» للسوبر أدمن: فرعٌ أو عدّة فروع، ولا شيء = عامة. */
export function BranchScopeField({
    value,
    onChange,
    branches,
    error,
}: {
    value: string;
    onChange: (value: string) => void;
    branches: { id: number; name: string }[];
    error?: string;
}) {
    return (
        <div className="space-y-1">
            <FilterMultiSelect
                label="النطاق"
                value={value}
                onChange={onChange}
                options={branches.map((b) => ({ value: b.id.toString(), label: b.name }))}
                allLabel="عامة (كل الفروع)"
                searchPlaceholder="ابحث عن فرع..."
            />
            <InputError message={error} />
        </div>
    );
}
