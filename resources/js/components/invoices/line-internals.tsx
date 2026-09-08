import { formatCurrency } from '@/lib/utils';

/** ما يحتاجه المكوّن من السطر — تقبله شاشة الفاتورة وطابور المراجعة معاً. */
interface InternalCostLine {
    materialsCost: number | null;
    materialsTotal: number | null;
    commissionAmount: number | null;
    tierApplied: number | null;
}

/**
 * تاسك 94 — أرقام السطر الداخلية: تكلفة الخامة، وعمولة الموظف عنه، والشريحة
 * المطبَّقة. الخادم يُرسلها null لمن لا يملك رؤيتها ولكل ورقة طباعة، فلا شيء
 * هنا يُخفي شيئاً — إن وصل الرقم فصاحبُه يستحقّ رؤيته.
 *
 * تُرسم سطوراً مكتومة تحت اسم الصنف على منوال سطر «صاحب العمولة»، لا أعمدةً
 * جديدة: الجدول يُعرض بطاقاتٍ على الجوال ولا يحتمل عموداً سادساً.
 */
export default function LineInternals({ line }: { line: InternalCostLine }) {
    const hasMaterials = line.materialsTotal != null && line.materialsTotal > 0;
    const hasCommission = line.commissionAmount != null && line.commissionAmount > 0;

    if (!hasMaterials && !hasCommission && line.tierApplied == null) {
        return null;
    }

    return (
        <>
            {hasMaterials && (
                <span className="text-muted-foreground block text-xs">
                    الخامات: {formatCurrency(line.materialsCost ?? 0)} للوحدة — {formatCurrency(line.materialsTotal ?? 0)} للسطر
                </span>
            )}
            {hasCommission && (
                <span className="text-muted-foreground block text-xs">
                    عمولة الموظف عن السطر: {formatCurrency(line.commissionAmount ?? 0)}
                    {line.tierApplied != null && <> — الشريحة {line.tierApplied}</>}
                </span>
            )}
            {!hasCommission && line.tierApplied != null && (
                <span className="text-muted-foreground block text-xs">الشريحة المطبَّقة: {line.tierApplied}</span>
            )}
        </>
    );
}
