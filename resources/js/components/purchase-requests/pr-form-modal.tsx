import { store } from '@/actions/App/Http/Controllers/PurchaseRequestController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { formatCurrency } from '@/lib/utils';
import { type PrBranchOption, type PrProductOption } from '@/types/purchase-request';
import { useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';

/** Sentinel for "an item that is not in the inventory yet" — see item_name. */
const CUSTOM_ITEM = '__custom__';

interface LineInput {
    // Every field is a string so the shape stays assignable to useForm's
    // FormDataType index signature.
    [key: string]: string;
    product_id: string;
    item_name: string;
    qty: string;
    estimated_unit_cost: string;
    notes: string;
}

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    products: PrProductOption[];
    /** Non-empty for super-admin only; everyone else files for their own branch. */
    branches: PrBranchOption[];
}

const emptyLine: LineInput = { product_id: '', item_name: '', qty: '1', estimated_unit_cost: '', notes: '' };

export default function PrFormModal({ open, onOpenChange, products, branches }: Props) {
    const { data, setData, post, processing, errors, reset } = useForm<{
        branch_id: string;
        notes: string;
        lines: LineInput[];
    }>({
        branch_id: '',
        notes: '',
        lines: [{ ...emptyLine }],
    });

    const picksBranch = branches.length > 0;
    const branchProducts = picksBranch ? products.filter((p) => p.branchId.toString() === data.branch_id) : products;

    const setLine = (index: number, key: keyof LineInput, value: string) => {
        setData(
            'lines',
            data.lines.map((line, i) => (i === index ? { ...line, [key]: value } : line)),
        );
    };

    const onProductChange = (index: number, value: string) => {
        const product = branchProducts.find((p) => p.id.toString() === value);

        setData(
            'lines',
            data.lines.map((line, i) =>
                i === index
                    ? {
                          ...line,
                          product_id: value === CUSTOM_ITEM ? '' : value,
                          item_name: product ? product.name : '',
                          estimated_unit_cost: line.estimated_unit_cost || (product ? product.costPrice.toString() : ''),
                      }
                    : line,
            ),
        );
    };

    const addLine = () => setData('lines', [...data.lines, { ...emptyLine }]);
    const removeLine = (index: number) =>
        setData(
            'lines',
            data.lines.filter((_, i) => i !== index),
        );

    const total = data.lines.reduce((sum, line) => {
        const qty = parseFloat(line.qty) || 0;
        const cost = parseFloat(line.estimated_unit_cost) || 0;
        return sum + qty * cost;
    }, 0);

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        post(store.url(), {
            onSuccess: () => {
                onOpenChange(false);
                reset();
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>طلب شراء جديد</DialogTitle>
                </DialogHeader>

                <form id="pr-form" onSubmit={handleSubmit} className="max-h-[70vh] space-y-6 overflow-y-auto px-1 py-2">
                    {picksBranch && (
                        <div className="space-y-1 sm:max-w-xs">
                            <Label htmlFor="pr-branch">الفرع</Label>
                            <Select value={data.branch_id} onValueChange={(val) => setData('branch_id', val)}>
                                <SelectTrigger id="pr-branch">
                                    <SelectValue placeholder="اختر الفرع" />
                                </SelectTrigger>
                                <SelectContent>
                                    {branches.map((branch) => (
                                        <SelectItem key={branch.id} value={branch.id.toString()}>
                                            {branch.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.branch_id} />
                        </div>
                    )}

                    <div className="rounded-lg border">
                        <div className="flex items-center justify-between border-b px-4 py-3">
                            <h2 className="font-semibold">الأصناف المطلوبة</h2>
                            <Button type="button" variant="outline" size="sm" onClick={addLine}>
                                <Plus className="size-4" /> إضافة صنف
                            </Button>
                        </div>

                        <div className="space-y-4 p-4">
                            {data.lines.map((line, index) => {
                                const isCustom = line.product_id === '';

                                return (
                                    <div key={index} className="space-y-2 rounded-md border border-dashed p-3">
                                        <div className="grid grid-cols-12 items-end gap-3">
                                            <div className="col-span-12 space-y-1 sm:col-span-5">
                                                <Label>الصنف</Label>
                                                <Select
                                                    value={isCustom ? CUSTOM_ITEM : line.product_id}
                                                    onValueChange={(val) => onProductChange(index, val)}
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue placeholder="اختر من المخزون" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value={CUSTOM_ITEM}>صنف غير مُعرَّف (كتابة يدوية)</SelectItem>
                                                        {branchProducts.map((product) => (
                                                            <SelectItem key={product.id} value={product.id.toString()}>
                                                                {product.name}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                                <InputError message={errors[`lines.${index}.product_id` as keyof typeof errors]} />
                                            </div>

                                            <div className="col-span-6 space-y-1 sm:col-span-3">
                                                <Label>الكمية</Label>
                                                <Input
                                                    type="number"
                                                    min="1"
                                                    dir="ltr"
                                                    value={line.qty}
                                                    onChange={(e) => setLine(index, 'qty', e.target.value)}
                                                />
                                                <InputError message={errors[`lines.${index}.qty` as keyof typeof errors]} />
                                            </div>

                                            <div className="col-span-6 space-y-1 sm:col-span-3">
                                                <Label>السعر التقديري</Label>
                                                <Input
                                                    type="number"
                                                    min="0"
                                                    step="0.01"
                                                    dir="ltr"
                                                    placeholder="اختياري"
                                                    value={line.estimated_unit_cost}
                                                    onChange={(e) => setLine(index, 'estimated_unit_cost', e.target.value)}
                                                />
                                                <InputError message={errors[`lines.${index}.estimated_unit_cost` as keyof typeof errors]} />
                                            </div>

                                            <div className="col-span-12 flex items-center justify-between gap-2 sm:col-span-1">
                                                {data.lines.length > 1 && (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        className="text-destructive hover:text-destructive"
                                                        onClick={() => removeLine(index)}
                                                    >
                                                        <Trash2 className="h-3.5 w-3.5" />
                                                    </Button>
                                                )}
                                            </div>
                                        </div>

                                        {isCustom && (
                                            <div className="space-y-1">
                                                <Label>اسم الصنف</Label>
                                                <Input
                                                    value={line.item_name}
                                                    placeholder="اكتب اسم الصنف المطلوب"
                                                    onChange={(e) => setLine(index, 'item_name', e.target.value)}
                                                />
                                                <InputError message={errors[`lines.${index}.item_name` as keyof typeof errors]} />
                                            </div>
                                        )}

                                        <div className="space-y-1">
                                            <Label>ملاحظة على الصنف</Label>
                                            <Input
                                                value={line.notes}
                                                placeholder="اختياري — مقاس، لون، مواصفات..."
                                                onChange={(e) => setLine(index, 'notes', e.target.value)}
                                            />
                                            <InputError message={errors[`lines.${index}.notes` as keyof typeof errors]} />
                                        </div>
                                    </div>
                                );
                            })}
                            {typeof errors.lines === 'string' && <InputError message={errors.lines} />}
                        </div>

                        <div className="flex items-center justify-end gap-2 border-t px-4 py-3">
                            <span className="text-muted-foreground text-sm">الإجمالي التقديري:</span>
                            <span dir="ltr" className="text-lg font-bold tabular-nums">
                                {formatCurrency(total)}
                            </span>
                        </div>
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="pr-notes">ملاحظات عامة</Label>
                        <textarea
                            id="pr-notes"
                            rows={3}
                            value={data.notes}
                            onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) => setData('notes', e.target.value)}
                            placeholder="اختياري — سبب الطلب أو أي تفاصيل إضافية"
                            className="border-input bg-background ring-offset-background placeholder:text-muted-foreground focus-visible:ring-ring flex min-h-[80px] w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                        />
                        <InputError message={errors.notes} />
                    </div>
                </form>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        إلغاء
                    </Button>
                    <Button type="submit" form="pr-form" disabled={processing}>
                        {processing ? 'جاري الإرسال...' : 'إرسال الطلب'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
