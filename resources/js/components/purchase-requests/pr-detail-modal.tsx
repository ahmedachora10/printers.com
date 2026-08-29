import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { formatCurrency } from '@/lib/utils';
import purchaseRequests from '@/routes/purchase-requests';
import { PR_STATUS_BADGE, type PrSupplierOption, type PurchaseRequest } from '@/types/purchase-request';
import { useForm } from '@inertiajs/react';
import { Check, ShoppingCart, X } from 'lucide-react';
import { useState } from 'react';

interface Props {
    request: PurchaseRequest | null;
    onOpenChange: (open: boolean) => void;
    suppliers: PrSupplierOption[];
}

type Panel = 'none' | 'reject' | 'convert';

/** تاسك 67: pieces stay whole, square metres keep their two decimals. */
const unitLabel = (isSqm: boolean) => (isSqm ? 'م²' : 'قطعة');
const formatQty = (qty: number) => (Number.isInteger(qty) ? qty.toString() : qty.toFixed(2));

export default function PrDetailModal({ request, onOpenChange, suppliers }: Props) {
    const [panel, setPanel] = useState<Panel>('none');

    const approveForm = useForm({});
    const rejectForm = useForm({ decision_reason: '' });
    const convertForm = useForm({ supplier_id: '', order_date: new Date().toISOString().slice(0, 10), expected_delivery: '' });

    const close = () => {
        setPanel('none');
        rejectForm.reset();
        convertForm.reset();
        onOpenChange(false);
    };

    if (!request) return null;

    const branchSuppliers = suppliers.filter((s) => s.branchId === request.branchId);
    const processing = approveForm.processing || rejectForm.processing || convertForm.processing;

    const approve = () => approveForm.patch(purchaseRequests.approve(request.id).url, { onSuccess: close, preserveScroll: true });

    const reject = (e: React.FormEvent) => {
        e.preventDefault();
        rejectForm.patch(purchaseRequests.reject(request.id).url, { onSuccess: close, preserveScroll: true });
    };

    const convert = (e: React.FormEvent) => {
        e.preventDefault();
        convertForm.post(purchaseRequests.convert(request.id).url, { onSuccess: close });
    };

    return (
        <Dialog open onOpenChange={(open) => (open ? undefined : close())}>
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <span>طلب شراء رقم {request.id}</span>
                        <Badge variant="outline" className={PR_STATUS_BADGE[request.status]}>
                            {request.statusLabel}
                        </Badge>
                    </DialogTitle>
                </DialogHeader>

                <div className="max-h-[65vh] space-y-4 overflow-y-auto px-1">
                    <dl className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
                        <div>
                            <dt className="text-muted-foreground">مقدّم الطلب</dt>
                            <dd className="font-medium">{request.requestedByName ?? '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">الفرع</dt>
                            <dd className="font-medium">{request.branchName ?? '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">تاريخ الطلب</dt>
                            <dd dir="ltr" className="font-medium">
                                {request.createdAt ?? '—'}
                            </dd>
                        </div>
                        {request.decidedByName && (
                            <div>
                                <dt className="text-muted-foreground">القرار من</dt>
                                <dd className="font-medium">{request.decidedByName}</dd>
                            </div>
                        )}
                        {request.decidedAt && (
                            <div>
                                <dt className="text-muted-foreground">تاريخ القرار</dt>
                                <dd dir="ltr" className="font-medium">
                                    {request.decidedAt}
                                </dd>
                            </div>
                        )}
                        {request.purchaseOrderNumber && (
                            <div>
                                <dt className="text-muted-foreground">أمر الشراء</dt>
                                <dd className="font-mono text-xs tracking-wider">{request.purchaseOrderNumber}</dd>
                            </div>
                        )}
                    </dl>

                    {request.notes && (
                        <div className="bg-muted/40 rounded-md border p-3 text-sm">
                            <p className="mb-1 font-medium">ملاحظات مقدّم الطلب</p>
                            <p className="text-muted-foreground">{request.notes}</p>
                        </div>
                    )}

                    {request.decisionReason && (
                        <div className="flex gap-2 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200">
                            <X className="mt-0.5 size-4 shrink-0" />
                            <div>
                                <p className="font-medium">سبب الرفض</p>
                                <p>{request.decisionReason}</p>
                            </div>
                        </div>
                    )}

                    <div className="overflow-x-auto rounded-md border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-muted-foreground text-xs">
                                <tr>
                                    <th className="p-2 text-right font-medium">الصنف</th>
                                    <th className="p-2 text-right font-medium">الكمية</th>
                                    <th className="p-2 text-right font-medium">السعر التقديري</th>
                                    <th className="p-2 text-right font-medium">الإجمالي</th>
                                </tr>
                            </thead>
                            <tbody>
                                {(request.lines ?? []).map((line) => (
                                    <tr key={line.id} className="border-t">
                                        <td className="p-2">
                                            <span>{line.itemName}</span>
                                            {line.productId === null && (
                                                <Badge variant="outline" className="ms-2 border-amber-200 bg-amber-50 text-[10px] text-amber-800">
                                                    غير مُعرَّف بالمخزون
                                                </Badge>
                                            )}
                                            {line.notes && <p className="text-muted-foreground mt-0.5 text-xs">{line.notes}</p>}
                                        </td>
                                        <td dir="ltr" className="p-2 text-right tabular-nums">
                                            {/* تاسك 67: the approver has to see what they are approving —
                                                2 pieces and 7.10 m² are not the same request. */}
                                            {formatQty(line.qty)} <span className="text-muted-foreground text-xs">{unitLabel(line.isSqm)}</span>
                                        </td>
                                        <td dir="ltr" className="p-2 text-right tabular-nums">
                                            {line.estimatedUnitCost === null ? '—' : formatCurrency(line.estimatedUnitCost)}
                                        </td>
                                        <td dir="ltr" className="p-2 text-right tabular-nums">
                                            {formatCurrency(line.estimatedSubtotal)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot>
                                <tr className="bg-muted/30 border-t font-semibold">
                                    <td className="p-2" colSpan={3}>
                                        الإجمالي التقديري
                                    </td>
                                    <td dir="ltr" className="p-2 text-right tabular-nums">
                                        {formatCurrency(request.estimatedTotal ?? 0)}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    {panel === 'reject' && (
                        <form id="pr-reject-form" onSubmit={reject} className="space-y-1 rounded-md border p-3">
                            <Label htmlFor="pr-reason">سبب الرفض</Label>
                            <textarea
                                id="pr-reason"
                                rows={3}
                                value={rejectForm.data.decision_reason}
                                onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) => rejectForm.setData('decision_reason', e.target.value)}
                                placeholder="يُعرض السبب لمقدّم الطلب"
                                className="border-input bg-background ring-offset-background placeholder:text-muted-foreground focus-visible:ring-ring flex min-h-[80px] w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                            />
                            <InputError message={rejectForm.errors.decision_reason} />
                        </form>
                    )}

                    {panel === 'convert' && (
                        <form id="pr-convert-form" onSubmit={convert} className="grid grid-cols-1 gap-3 rounded-md border p-3 sm:grid-cols-3">
                            <div className="space-y-1">
                                <Label htmlFor="pr-supplier">المورد</Label>
                                <Select value={convertForm.data.supplier_id} onValueChange={(val) => convertForm.setData('supplier_id', val)}>
                                    <SelectTrigger id="pr-supplier">
                                        <SelectValue placeholder="اختياري" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {branchSuppliers.map((supplier) => (
                                            <SelectItem key={supplier.id} value={supplier.id.toString()}>
                                                {supplier.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={convertForm.errors.supplier_id} />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="pr-order-date">تاريخ الأمر</Label>
                                <Input
                                    id="pr-order-date"
                                    type="date"
                                    value={convertForm.data.order_date}
                                    onChange={(e) => convertForm.setData('order_date', e.target.value)}
                                />
                                <InputError message={convertForm.errors.order_date} />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="pr-expected">التسليم المتوقع</Label>
                                <Input
                                    id="pr-expected"
                                    type="date"
                                    value={convertForm.data.expected_delivery}
                                    onChange={(e) => convertForm.setData('expected_delivery', e.target.value)}
                                />
                                <InputError message={convertForm.errors.expected_delivery} />
                            </div>
                            <p className="text-muted-foreground col-span-full text-xs">
                                تُنقل الأصناف المُعرَّفة بالمخزون فقط إلى أمر الشراء، ويُنشأ الأمر كمسودة دون أي حركة مخزون.
                            </p>
                        </form>
                    )}
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={close} disabled={processing}>
                        إغلاق
                    </Button>

                    {request.canDecide && panel !== 'convert' && (
                        <>
                            {panel === 'reject' ? (
                                <Button type="submit" form="pr-reject-form" variant="destructive" disabled={processing}>
                                    {rejectForm.processing ? 'جاري الرفض...' : 'تأكيد الرفض'}
                                </Button>
                            ) : (
                                <Button type="button" variant="destructive" onClick={() => setPanel('reject')} disabled={processing}>
                                    <X className="size-4" /> رفض
                                </Button>
                            )}
                            {panel === 'none' && (
                                <Button type="button" onClick={approve} disabled={processing}>
                                    <Check className="size-4" /> {approveForm.processing ? 'جاري الاعتماد...' : 'اعتماد'}
                                </Button>
                            )}
                        </>
                    )}

                    {request.canConvert &&
                        (panel === 'convert' ? (
                            <Button type="submit" form="pr-convert-form" disabled={processing}>
                                {convertForm.processing ? 'جاري التحويل...' : 'تأكيد التحويل'}
                            </Button>
                        ) : (
                            <Button type="button" onClick={() => setPanel('convert')} disabled={processing}>
                                <ShoppingCart className="size-4" /> تحويل إلى أمر شراء
                            </Button>
                        ))}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
