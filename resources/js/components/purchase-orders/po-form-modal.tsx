import { store } from '@/actions/App/Http/Controllers/PurchaseOrderController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { formatCurrency } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';

export interface PoSupplierOption {
    id: number;
    name: string;
}

export interface PoProductOption {
    id: number;
    name: string;
    sku: string | null;
    costPrice: number;
}

interface LineInput {
    product_id: string;
    ordered_qty: string;
    unit_cost: string;
}

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    suppliers: PoSupplierOption[];
    products: PoProductOption[];
}

const emptyLine: LineInput = { product_id: '', ordered_qty: '1', unit_cost: '' };

export default function PoFormModal({ open, onOpenChange, suppliers, products }: Props) {
    const { data, setData, post, processing, errors, reset } = useForm<{
        supplier_id: string;
        order_date: string;
        expected_delivery: string;
        notes: string;
        lines: LineInput[];
    }>({
        supplier_id: '',
        order_date: new Date().toISOString().slice(0, 10),
        expected_delivery: '',
        notes: '',
        lines: [{ ...emptyLine }],
    });

    const setLine = (index: number, key: keyof LineInput, value: string) => {
        const lines = data.lines.map((line, i) => (i === index ? { ...line, [key]: value } : line));
        setData('lines', lines);
    };

    const onProductChange = (index: number, productId: string) => {
        const product = products.find((p) => p.id.toString() === productId);
        const lines = data.lines.map((line, i) =>
            i === index
                ? { ...line, product_id: productId, unit_cost: line.unit_cost || (product ? product.costPrice.toString() : '') }
                : line,
        );
        setData('lines', lines);
    };

    const addLine = () => setData('lines', [...data.lines, { ...emptyLine }]);
    const removeLine = (index: number) => setData('lines', data.lines.filter((_, i) => i !== index));

    const total = data.lines.reduce((sum, line) => {
        const qty = parseFloat(line.ordered_qty) || 0;
        const cost = parseFloat(line.unit_cost) || 0;
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
                    <DialogTitle>أمر شراء جديد</DialogTitle>
                </DialogHeader>

                <form id="po-form" onSubmit={handleSubmit} className="max-h-[70vh] space-y-6 overflow-y-auto px-1 py-2">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div className="space-y-1">
                            <Label htmlFor="po-supplier">المورد</Label>
                            <Select value={data.supplier_id} onValueChange={(val) => setData('supplier_id', val)}>
                                <SelectTrigger id="po-supplier">
                                    <SelectValue placeholder="اختياري" />
                                </SelectTrigger>
                                <SelectContent>
                                    {suppliers.map((s) => (
                                        <SelectItem key={s.id} value={s.id.toString()}>
                                            {s.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.supplier_id} />
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="po-order-date">تاريخ الأمر</Label>
                            <Input
                                id="po-order-date"
                                type="date"
                                value={data.order_date}
                                onChange={(e) => setData('order_date', e.target.value)}
                            />
                            <InputError message={errors.order_date} />
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="po-expected">التسليم المتوقع</Label>
                            <Input
                                id="po-expected"
                                type="date"
                                value={data.expected_delivery}
                                onChange={(e) => setData('expected_delivery', e.target.value)}
                            />
                            <InputError message={errors.expected_delivery} />
                        </div>
                    </div>

                    <div className="rounded-lg border">
                        <div className="flex items-center justify-between border-b px-4 py-3">
                            <h2 className="font-semibold">البنود</h2>
                            <Button type="button" variant="outline" size="sm" onClick={addLine}>
                                <Plus className="size-4" /> إضافة بند
                            </Button>
                        </div>

                        <div className="space-y-3 p-4">
                            {data.lines.map((line, index) => (
                                <div key={index} className="grid grid-cols-12 items-end gap-3">
                                    <div className="col-span-5 space-y-1">
                                        {index === 0 && <Label>المنتج</Label>}
                                        <Select value={line.product_id} onValueChange={(val) => onProductChange(index, val)}>
                                            <SelectTrigger>
                                                <SelectValue placeholder="اختر المنتج" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {products.map((p) => (
                                                    <SelectItem key={p.id} value={p.id.toString()}>
                                                        {p.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError message={errors[`lines.${index}.product_id` as keyof typeof errors]} />
                                    </div>

                                    <div className="col-span-2 space-y-1">
                                        {index === 0 && <Label>الكمية</Label>}
                                        <Input
                                            type="number"
                                            min="1"
                                            dir="ltr"
                                            value={line.ordered_qty}
                                            onChange={(e) => setLine(index, 'ordered_qty', e.target.value)}
                                        />
                                        <InputError message={errors[`lines.${index}.ordered_qty` as keyof typeof errors]} />
                                    </div>

                                    <div className="col-span-3 space-y-1">
                                        {index === 0 && <Label>سعر الوحدة</Label>}
                                        <Input
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            dir="ltr"
                                            value={line.unit_cost}
                                            onChange={(e) => setLine(index, 'unit_cost', e.target.value)}
                                        />
                                        <InputError message={errors[`lines.${index}.unit_cost` as keyof typeof errors]} />
                                    </div>

                                    <div className="col-span-2 flex items-center justify-between gap-2">
                                        <span dir="ltr" className="text-sm tabular-nums text-muted-foreground">
                                            {formatCurrency((parseFloat(line.ordered_qty) || 0) * (parseFloat(line.unit_cost) || 0))}
                                        </span>
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
                            ))}
                            {typeof errors.lines === 'string' && <InputError message={errors.lines} />}
                        </div>

                        <div className="flex items-center justify-end gap-2 border-t px-4 py-3">
                            <span className="text-sm text-muted-foreground">الإجمالي:</span>
                            <span dir="ltr" className="text-lg font-bold tabular-nums">
                                {formatCurrency(total)}
                            </span>
                        </div>
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="po-notes">ملاحظات</Label>
                        <textarea
                            id="po-notes"
                            rows={3}
                            value={data.notes}
                            onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) => setData('notes', e.target.value)}
                            placeholder="اختياري"
                            className="flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                        />
                        <InputError message={errors.notes} />
                    </div>
                </form>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        إلغاء
                    </Button>
                    <Button type="submit" form="po-form" disabled={processing}>
                        {processing ? 'جاري الحفظ...' : 'إنشاء أمر الشراء'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
