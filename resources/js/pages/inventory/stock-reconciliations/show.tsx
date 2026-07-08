import { DataTable, type ColumnDef } from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { reconciliationBadgeClass, type StockReconciliation, type StockReconciliationLine } from '@/types/stock-reconciliation';
import { router } from '@inertiajs/react';
import { CheckCheck, Save, Search, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import inventory from '@/routes/inventory';

interface Props {
    reconciliation: StockReconciliation;
    canManage: boolean;
}

export default function StockReconciliationShow({ reconciliation, canManage }: Props) {
    const lines = useMemo(() => reconciliation.lines ?? [], [reconciliation.lines]);

    const [counts, setCounts] = useState<Record<number, string>>(() =>
        Object.fromEntries(lines.map((line) => [line.id, String(line.physicalQty)])),
    );
    const [search, setSearch] = useState('');
    const [confirm, setConfirm] = useState<null | 'complete' | 'delete'>(null);
    const [saving, setSaving] = useState(false);

    const editable = canManage && !reconciliation.isCompleted;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'المستودع', href: inventory.products.index().url },
        { title: 'جرد المخزون', href: inventory.stockReconciliations.index().url },
        { title: `جرد #${reconciliation.id}`, href: inventory.stockReconciliations.show(reconciliation.id).url },
    ];

    const parsedCount = (line: StockReconciliationLine): number => {
        const raw = counts[line.id];
        const value = Number.parseInt(raw ?? '', 10);
        return Number.isNaN(value) ? line.physicalQty : value;
    };

    const isDirty = lines.some((line) => parsedCount(line) !== line.physicalQty);

    const variantCount = lines.filter((line) =>
        reconciliation.isCompleted ? line.variance !== 0 : parsedCount(line) - line.systemQty !== 0,
    ).length;

    const filteredLines = useMemo(() => {
        const term = search.trim().toLowerCase();
        if (!term) return lines;
        return lines.filter(
            (line) =>
                (line.productName ?? '').toLowerCase().includes(term) ||
                (line.sku ?? '').toLowerCase().includes(term),
        );
    }, [lines, search]);

    const handleSave = (onSaved?: () => void) => {
        setSaving(true);
        router.put(
            inventory.stockReconciliations.counts(reconciliation.id).url,
            {
                counts: lines.map((line) => ({ line_id: line.id, physical_qty: parsedCount(line) })),
            },
            {
                preserveScroll: true,
                onSuccess: onSaved,
                onFinish: () => setSaving(false),
            },
        );
    };

    const handleComplete = () =>
        router.post(inventory.stockReconciliations.complete(reconciliation.id).url, {}, {
            preserveScroll: true,
            onFinish: () => setConfirm(null),
        });

    const handleDelete = () =>
        router.delete(inventory.stockReconciliations.destroy(reconciliation.id).url, {
            onFinish: () => setConfirm(null),
        });

    const varianceCell = (line: StockReconciliationLine) => {
        const variance = reconciliation.isCompleted ? line.variance : parsedCount(line) - line.systemQty;

        if (variance === 0) {
            return <span className="text-muted-foreground">—</span>;
        }

        return (
            <span dir="ltr" className={`font-semibold tabular-nums ${variance > 0 ? 'text-green-700' : 'text-destructive'}`}>
                {variance > 0 ? `+${variance}` : variance}
            </span>
        );
    };

    const columns = useMemo<ColumnDef<StockReconciliationLine>[]>(
        () => [
            {
                key: 'productName',
                header: 'المنتج',
                cell: (line) => <span className="font-medium">{line.productName ?? '—'}</span>,
            },
            {
                key: 'sku',
                header: 'SKU',
                cell: (line) => <span className="font-mono text-xs text-muted-foreground">{line.sku ?? '—'}</span>,
            },
            {
                key: 'systemQty',
                header: 'الرصيد الدفتري',
                cell: (line) => <span className="tabular-nums">{line.systemQty}</span>,
            },
            {
                key: 'physicalQty',
                header: 'الكمية الفعلية',
                cell: (line) =>
                    editable ? (
                        <Input
                            type="number"
                            min={0}
                            dir="ltr"
                            className="h-8 w-24 tabular-nums"
                            value={counts[line.id] ?? ''}
                            onChange={(e) => setCounts((prev) => ({ ...prev, [line.id]: e.target.value }))}
                        />
                    ) : (
                        <span className="tabular-nums">{line.physicalQty}</span>
                    ),
            },
            {
                key: 'variance',
                header: 'الفرق',
                cell: varianceCell,
            },
        ],
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [editable, counts, reconciliation.isCompleted],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="p-6">
                <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <h1 className="text-2xl font-bold">جرد المخزون #{reconciliation.id}</h1>
                        <Badge variant="outline" className={reconciliationBadgeClass(reconciliation)}>
                            {reconciliation.statusLabel}
                        </Badge>
                    </div>

                    {editable && (
                        <div className="flex flex-wrap items-center gap-2">
                            <Button size="sm" variant="outline" onClick={() => handleSave()} disabled={saving || !isDirty}>
                                <Save className="size-4" /> حفظ الكميات
                            </Button>
                            <Button size="sm" onClick={() => setConfirm('complete')} disabled={saving || isDirty}>
                                <CheckCheck className="size-4" /> اعتماد الجرد
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                className="text-destructive hover:text-destructive"
                                onClick={() => setConfirm('delete')}
                            >
                                <Trash2 className="size-4" /> حذف
                            </Button>
                        </div>
                    )}
                </div>

                {editable && isDirty && (
                    <p className="mb-4 text-sm text-amber-700">لديك كميات غير محفوظة — احفظ الكميات قبل اعتماد الجرد.</p>
                )}

                <div className="mb-6 grid grid-cols-1 gap-4 rounded-lg border p-4 text-sm sm:grid-cols-5">
                    <div>
                        <p className="text-muted-foreground">الفرع</p>
                        <p className="font-medium">{reconciliation.branchName ?? '—'}</p>
                    </div>
                    <div>
                        <p className="text-muted-foreground">بدأه</p>
                        <p className="font-medium">{reconciliation.initiatedByName ?? '—'}</p>
                    </div>
                    <div>
                        <p className="text-muted-foreground">تاريخ البدء</p>
                        <p className="font-medium" dir="ltr">{reconciliation.createdAt ?? '—'}</p>
                    </div>
                    <div>
                        <p className="text-muted-foreground">تاريخ الاعتماد</p>
                        <p className="font-medium" dir="ltr">{reconciliation.completedAt ?? '—'}</p>
                    </div>
                    <div>
                        <p className="text-muted-foreground">منتجات بها فروقات</p>
                        <p className={`font-medium tabular-nums ${variantCount > 0 ? 'text-amber-700' : ''}`}>
                            {variantCount} من {lines.length}
                        </p>
                    </div>
                </div>

                <div className="relative mb-4 w-72">
                    <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="بحث باسم المنتج أو SKU..."
                        className="h-9 ps-9 text-sm"
                    />
                </div>

                <DataTable columns={columns} data={filteredLines} keyExtractor={(line) => line.id} />

                {reconciliation.notes && (
                    <div className="mt-6 rounded-lg border bg-muted/30 p-4 text-sm">
                        <p className="mb-1 font-medium">ملاحظات</p>
                        <p className="text-muted-foreground">{reconciliation.notes}</p>
                    </div>
                )}
            </div>

            <Dialog open={!!confirm} onOpenChange={(open) => !open && setConfirm(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{confirm === 'complete' ? 'اعتماد الجرد' : 'تأكيد الحذف'}</DialogTitle>
                        <DialogDescription>
                            {confirm === 'complete'
                                ? variantCount > 0
                                    ? `سيتم تسجيل تسويات مخزون لعدد ${variantCount} منتج وفق الفروقات المجرودة. لا يمكن التراجع عن الاعتماد.`
                                    : 'لا توجد فروقات — سيتم اعتماد الجرد دون أي تسويات. لا يمكن التراجع عن الاعتماد.'
                                : `هل أنت متأكد من حذف الجرد #${reconciliation.id}؟ ستفقد الكميات المجرودة.`}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setConfirm(null)}>
                            تراجع
                        </Button>
                        {confirm === 'complete' ? (
                            <Button onClick={handleComplete}>اعتماد</Button>
                        ) : (
                            <Button variant="destructive" onClick={handleDelete}>
                                حذف
                            </Button>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
