import { newShipment, ShipmentFields, shipmentPayload, type ShipmentDraft } from '@/components/pos/shipment-fields';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { formatCurrency, formatDateTime } from '@/lib/utils';
import shipmentRequests from '@/routes/invoices/service/shipment-requests';
import { type Invoice } from '@/types/invoice';
import { type PosCustomer, type PosShippingProvider, type PosShippingZone } from '@/types/pos';
import { router } from '@inertiajs/react';
import { Bike, CheckCircle2, X } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

/** ما تحتاجه نافذة الطلب — `shipmentForm` في InvoiceController::show. */
export interface ShipmentRequestForm {
    providers: PosShippingProvider[];
    zones: PosShippingZone[];
    customer: PosCustomer | null;
    canEditFee: boolean;
}

/**
 * توصيلٌ بعد اعتماد الفاتورة («كنت سآخذها، أرسلوها»): نافذة الطلب بحقول نقطة
 * البيع نفسها. يذهب الطلب للمحاسب، ولا يمسّ الفاتورة حتى يعتمده.
 */
export function ShipmentRequestDialog({ open, onOpenChange, invoiceId, form }: { open: boolean; onOpenChange: (open: boolean) => void; invoiceId: number; form: ShipmentRequestForm }) {
    const [draft, setDraft] = useState<ShipmentDraft>(() => newShipment(form.customer));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    function close() {
        onOpenChange(false);
        setDraft(newShipment(form.customer));
        setErrors({});
    }

    function submit() {
        setSaving(true);
        router.post(
            shipmentRequests.store(invoiceId).url,
            { shipments: [shipmentPayload(draft, form.canEditFee)] },
            {
                preserveScroll: true,
                onError: (e) => setErrors(e),
                onSuccess: close,
                onFinish: () => setSaving(false),
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={(next) => !next && !saving && close()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>طلب توصيل</DialogTitle>
                    <DialogDescription>يُرسل للمحاسب، ويُضاف رسمه إلى الفاتورة عند اعتماده.</DialogDescription>
                </DialogHeader>
                <ShipmentFields
                    draft={draft}
                    index={0}
                    customer={form.customer}
                    providers={form.providers}
                    zones={form.zones}
                    canEditFee={form.canEditFee}
                    errors={errors}
                    onChange={setDraft}
                    onRemove={close}
                />
                <DialogFooter>
                    <Button variant="outline" onClick={close} disabled={saving}>
                        إلغاء
                    </Button>
                    <Button onClick={submit} disabled={saving || draft.providerId === null}>
                        <Bike className="size-4" /> إرسال الطلب
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

type PendingShipment = Invoice['pendingShipments'][number];

/** الطلبات المنتظرة على الفاتورة، وللمحاسب اعتمادها أو رفضها بسبب. */
export function PendingShipments({ invoice }: { invoice: Invoice }) {
    const [decision, setDecision] = useState<{ shipment: PendingShipment; mode: 'approve' | 'reject' } | null>(null);
    const [reason, setReason] = useState('');
    const [saving, setSaving] = useState(false);

    if (invoice.pendingShipments.length === 0) {
        return null;
    }

    function decide() {
        if (!decision) return;
        const args = { invoice: invoice.id, pendingShipment: decision.shipment.id };
        const options = {
            preserveScroll: true,
            onError: (e: Record<string, string>) => toast.error(Object.values(e)[0] ?? 'تعذّر حفظ القرار.'),
            onSuccess: () => setDecision(null),
            onFinish: () => setSaving(false),
        };

        setSaving(true);
        if (decision.mode === 'approve') {
            router.patch(shipmentRequests.approve(args).url, {}, options);
        } else {
            router.delete(shipmentRequests.reject(args).url, { ...options, data: { reason: reason.trim() } });
        }
    }

    return (
        <Card className="mb-6 border-amber-300 dark:border-amber-900">
            <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base">
                    <Bike className="size-4" /> طلبات توصيل بانتظار اعتماد المحاسب
                </CardTitle>
            </CardHeader>
            <CardContent className="divide-y">
                {invoice.pendingShipments.map((shipment) => (
                    <div key={shipment.id} className="flex flex-wrap items-center justify-between gap-3 py-2 text-sm">
                        <div className="min-w-0 space-y-0.5">
                            <p className="font-medium">
                                {shipment.providerName ?? '—'}
                                {shipment.zoneName && <span className="text-muted-foreground"> — {shipment.zoneName}</span>}
                            </p>
                            {shipment.address && <p className="text-muted-foreground text-xs whitespace-pre-line">{shipment.address}</p>}
                            <p className="text-muted-foreground text-xs">
                                {shipment.requestedByName && <>طلبه {shipment.requestedByName}</>}
                                {shipment.requestedAt && <> — {formatDateTime(shipment.requestedAt)}</>}
                            </p>
                        </div>
                        <div className="flex items-center gap-2">
                            {shipment.fee > 0 ? (
                                <span className="font-semibold" dir="ltr">
                                    {formatCurrency(shipment.fee)}
                                </span>
                            ) : (
                                <Badge variant="outline">مجاني</Badge>
                            )}
                            {invoice.canApproveShipment && (
                                <>
                                    <Button size="sm" className="bg-emerald-600 text-white hover:bg-emerald-700" onClick={() => setDecision({ shipment, mode: 'approve' })}>
                                        <CheckCircle2 className="size-4" /> اعتماد
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="text-destructive hover:text-destructive"
                                        onClick={() => {
                                            setReason('');
                                            setDecision({ shipment, mode: 'reject' });
                                        }}
                                    >
                                        <X className="size-4" /> رفض
                                    </Button>
                                </>
                            )}
                        </div>
                    </div>
                ))}
            </CardContent>

            <Dialog open={decision !== null} onOpenChange={(open) => !open && !saving && setDecision(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{decision?.mode === 'approve' ? 'اعتماد طلب التوصيل' : 'رفض طلب التوصيل'}</DialogTitle>
                        <DialogDescription>
                            {decision?.mode === 'reject'
                                ? 'يُحذف الطلب ويصل السبب لمن طلبه.'
                                : decision && decision.shipment.fee > 0
                                  ? `يُضاف ${formatCurrency(decision.shipment.fee)} إلى إجمالي الفاتورة ويبقى متبقّياً عليها حتى يُسجَّل تحصيله من «تسجيل دفعة» — الآن إن دفعه العميل، أو حين يعود به السائق.`
                                  : 'توصيلٌ مجاني — لا يتغيّر إجمالي الفاتورة، ويظهر في كشف التوصيل.'}
                        </DialogDescription>
                    </DialogHeader>
                    {decision?.mode === 'reject' && (
                        <div className="space-y-1">
                            <Label htmlFor="shipment-reject-reason">سبب الرفض</Label>
                            <textarea
                                id="shipment-reject-reason"
                                rows={3}
                                value={reason}
                                onChange={(e) => setReason(e.target.value)}
                                className="border-input bg-background focus-visible:ring-ring flex w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                            />
                        </div>
                    )}
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDecision(null)} disabled={saving}>
                            إلغاء
                        </Button>
                        <Button
                            variant={decision?.mode === 'reject' ? 'destructive' : 'default'}
                            onClick={decide}
                            disabled={saving || (decision?.mode === 'reject' && reason.trim() === '')}
                        >
                            {decision?.mode === 'reject' ? 'رفض الطلب' : 'اعتماد'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </Card>
    );
}
