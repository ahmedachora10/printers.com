import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { formatCurrency } from '@/lib/utils';
import { type EditServiceInvoiceShipment, type PosCustomer, type PosCustomerAddress, type PosShippingProvider, type PosShippingZone } from '@/types/pos';
import { X } from 'lucide-react';

/**
 * تاسك 170 — طلب توصيلٍ واحد في بطاقة «التوصيل» بنقطة البيع: سائقه وعنوانه
 * وشريحته وقيمته. الفاتورة تحمل منها ما شاءت، ورسمها مجموعها.
 */
export interface ShipmentDraft {
    /** مفتاح React — ثابتٌ للطلب الجديد الذي لا معرّف له بعد. */
    key: string;
    /** معرّف الطلب المحفوظ؛ يُعاد مع الحفظ فتبقى تسوية سائقه مربوطةً به. */
    id: number | null;
    providerId: number | null;
    zoneId: number | null;
    /** ما كتبته الإدارة؛ الفارغ = سعر الشريحة. */
    fee: string;
    distanceKm: string;
    customerAddressId: number | null;
    address: string;
    addressLabel: string;
    locationUrl: string;
    /** عنوانٌ جديد يُحفظ في دفتر العميل — إضافةً لا استبدالاً. */
    saveAddress: boolean;
}

let draftSeq = 0;

/** طلبٌ جديد، وعنوان العميل الافتراضيّ واقعٌ عليه — فلا يقدّر الكاشير مسافةً ولا يبحث عن حي. */
export function newShipment(customer: PosCustomer | null): ShipmentDraft {
    return withCustomerAddress(
        {
            key: `new-${++draftSeq}`,
            id: null,
            providerId: null,
            zoneId: null,
            fee: '',
            distanceKm: '',
            customerAddressId: null,
            address: '',
            addressLabel: '',
            locationUrl: '',
            saveAddress: false,
        },
        customer?.addresses?.find((address) => address.isDefault) ?? null,
    );
}

export function savedShipment(saved: EditServiceInvoiceShipment): ShipmentDraft {
    return {
        key: `saved-${saved.id}`,
        id: saved.id,
        providerId: saved.providerId,
        zoneId: saved.zoneId,
        fee: saved.fee ? String(saved.fee) : '',
        distanceKm: saved.distanceKm != null ? String(saved.distanceKm) : '',
        customerAddressId: saved.customerAddressId,
        address: saved.address ?? '',
        addressLabel: '',
        locationUrl: '',
        saveAddress: false,
    };
}

/** اختيار عنوانٍ من الدفتر (أو لا شيء = عنوان جديد) يملأ شريحته، وتُمسح القيمة المكتوبة. */
export function withCustomerAddress(draft: ShipmentDraft, address: PosCustomerAddress | null): ShipmentDraft {
    return {
        ...draft,
        customerAddressId: address?.id ?? null,
        address: address?.address ?? '',
        locationUrl: address?.locationUrl ?? '',
        addressLabel: '',
        saveAddress: false,
        ...(address?.deliveryZoneId != null ? { zoneId: address.deliveryZoneId, fee: '' } : {}),
    };
}

/**
 * القيمة المعروضة: ما كتبته الإدارة إن كتبت، وإلا سعر الشريحة. والموظف لا
 * يكتب أصلاً — الخادم يتجاهل ما يرسله ويأخذ سعر الشريحة.
 */
export function shipmentFee(draft: ShipmentDraft, zones: PosShippingZone[], canEditFee: boolean): number {
    const zonePrice = zones.find((zone) => zone.id === draft.zoneId)?.price ?? 0;

    return canEditFee && draft.fee !== '' ? Number(draft.fee) || 0 : zonePrice;
}

/** حمولة الطلب كما يقرؤها StoreServiceInvoiceRequest (`shipments.*`). */
export function shipmentPayload(draft: ShipmentDraft, canEditFee: boolean) {
    return {
        id: draft.id,
        provider_id: draft.providerId,
        zone_id: draft.zoneId,
        // القيمة تُرسل ممّن يملك كتابتها فقط؛ ومن سواه يأخذ الخادمُ سعرَ الشريحة.
        fee: canEditFee && draft.fee !== '' ? Number(draft.fee) : null,
        distance_km: draft.distanceKm !== '' ? Number(draft.distanceKm) : null,
        customer_address_id: draft.customerAddressId,
        address: draft.address.trim() || null,
        save_address: draft.saveAddress,
        address_label: draft.addressLabel.trim() || null,
        location_url: draft.locationUrl.trim() || null,
    };
}

/**
 * تاسك 93 — الشريحة التي تشمل مسافةً مكتوبة.
 *
 * المدى نصف مفتوح (`from <= d < to`) تماماً كـ`DeliveryZone::coversDistance()`
 * في الخادم، فالمسافة 5 تخصّ شريحة 5–10 وحدها لا 0–5. والأحياء لا تُقاس
 * بمسافة أبداً فتُستبعد من البحث.
 */
function zoneForDistance(zones: PosShippingZone[], km: number): PosShippingZone | null {
    return (
        zones.find(
            (zone) =>
                zone.type === 'distance' &&
                zone.fromKm !== null &&
                km >= zone.fromKm &&
                (zone.toKm === null || km < zone.toKm),
        ) ?? null
    );
}

interface ShipmentFieldsProps {
    draft: ShipmentDraft;
    /** ترتيبه في البطاقة — لمعرّفات الحقول ومفاتيح أخطاء الخادم (`shipments.N.*`). */
    index: number;
    customer: PosCustomer | null;
    providers: PosShippingProvider[];
    zones: PosShippingZone[];
    canEditFee: boolean;
    errors: Record<string, string>;
    onChange: (next: ShipmentDraft) => void;
    onRemove: () => void;
}

export function ShipmentFields({ draft, index, customer, providers, zones, canEditFee, errors, onChange, onRemove }: ShipmentFieldsProps) {
    const id = (field: string) => `shipment-${index}-${field}`;
    const error = (field: string) => errors[`shipments.${index}.${field}`];
    const set = (patch: Partial<ShipmentDraft>) => onChange({ ...draft, ...patch });
    // اختيار شريحة: تُملأ قيمتها من سعرها، ما لم تكن الإدارة قد كتبت قيمةً.
    const applyZone = (zoneId: number | null) => set({ zoneId, fee: '' });

    /**
     * كتابة المسافة تنتقي شريحتها وحدها. والبحث في شرائح المسافة فقط — الأحياء
     * لا تُقاس بمسافة، فكتابةُ رقمٍ لا تزيح حيّاً اختاره الكاشير عمداً.
     */
    function applyDistance(value: string) {
        const km = Number(value);
        const match = value === '' || Number.isNaN(km) ? null : zoneForDistance(zones, km);
        set(match ? { distanceKm: value, zoneId: match.id, fee: '' } : { distanceKm: value });
    }

    return (
        <div className="space-y-3 rounded-md border p-3">
            <div className="flex items-end gap-2">
                <div className="min-w-0 flex-1 space-y-1">
                    <Label htmlFor={id('provider')} className="text-xs">
                        السائق أو شركة التوصيل
                    </Label>
                    <Select value={draft.providerId === null ? '' : String(draft.providerId)} onValueChange={(v) => set({ providerId: Number(v) })}>
                        <SelectTrigger id={id('provider')}>
                            <SelectValue placeholder="اختر السائق" />
                        </SelectTrigger>
                        <SelectContent>
                            {providers.map((provider) => (
                                <SelectItem key={provider.id} value={String(provider.id)}>
                                    {provider.name} — {provider.typeLabel}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                <Button type="button" variant="ghost" size="icon" className="size-11 shrink-0 md:size-9" onClick={onRemove} aria-label="حذف طلب التوصيل" title="حذف طلب التوصيل">
                    <X className="size-4" />
                </Button>
            </div>
            {error('provider_id') && <p className="text-destructive text-xs">{error('provider_id')}</p>}

            {draft.providerId !== null && (
                <>
                    {/* عناوين العميل المحفوظة: اختيارُ عنوانٍ يملأ شريحته
                        وسعرها، فلا تُقدَّر مسافةٌ ولا يُبحث عن حي. */}
                    {(customer?.addresses?.length ?? 0) > 0 && (
                        <div className="space-y-1">
                            <Label htmlFor={id('address-pick')} className="text-xs">
                                عنوان العميل
                            </Label>
                            <Select
                                value={draft.customerAddressId === null ? 'new' : String(draft.customerAddressId)}
                                onValueChange={(v) =>
                                    onChange(withCustomerAddress(draft, v === 'new' ? null : (customer?.addresses.find((a) => a.id === Number(v)) ?? null)))
                                }
                            >
                                <SelectTrigger id={id('address-pick')}>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {customer?.addresses.map((address) => (
                                        <SelectItem key={address.id} value={String(address.id)}>
                                            {address.displayLabel}
                                        </SelectItem>
                                    ))}
                                    <SelectItem value="new">عنوان جديد…</SelectItem>
                                </SelectContent>
                            </Select>
                            {error('customer_address_id') && <p className="text-destructive text-xs">{error('customer_address_id')}</p>}
                        </div>
                    )}

                    <div className="space-y-1">
                        <Label htmlFor={id('address')} className="text-xs">
                            العنوان
                        </Label>
                        <textarea
                            id={id('address')}
                            rows={2}
                            value={draft.address}
                            onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) => set({ address: e.target.value })}
                            placeholder="الحي، الشارع، رقم المبنى"
                            className="border-input bg-background ring-offset-background placeholder:text-muted-foreground focus-visible:ring-ring flex min-h-[56px] w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                        />
                        {error('address') && <p className="text-destructive text-xs">{error('address')}</p>}
                    </div>

                    {/* عنوانٌ جديد لعميلٍ له بطاقة يُضاف إلى دفتره —
                        إضافةً لا استبدالاً لعنوانه القائم. */}
                    {customer && draft.customerAddressId === null && draft.address.trim() !== '' && (
                        <div className="space-y-2 rounded-md border border-dashed p-2">
                            <label className="flex cursor-pointer items-center gap-2 text-xs">
                                <Checkbox checked={draft.saveAddress} onCheckedChange={(checked) => set({ saveAddress: checked === true })} />
                                حفظ العنوان في بطاقة العميل
                            </label>
                            {draft.saveAddress && (
                                <Input
                                    value={draft.addressLabel}
                                    onChange={(e) => set({ addressLabel: e.target.value })}
                                    placeholder="اسم العنوان — المنزل، المكتب…"
                                    className="h-8 text-xs"
                                />
                            )}
                        </div>
                    )}

                    <div className="grid grid-cols-2 gap-2">
                        <div className="space-y-1">
                            <Label htmlFor={id('zone')} className="text-xs">
                                الحي أو الشريحة
                            </Label>
                            <Select value={draft.zoneId === null ? 'none' : String(draft.zoneId)} onValueChange={(v) => applyZone(v === 'none' ? null : Number(v))}>
                                <SelectTrigger id={id('zone')}>
                                    <SelectValue placeholder="اختر" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">بدون</SelectItem>
                                    {/* الأحياء أولاً: الطريق الأغلب في نقطة البيع. */}
                                    {zones.map((zone) => (
                                        <SelectItem key={zone.id} value={String(zone.id)}>
                                            {zone.name}
                                            {zone.rangeLabel ? ` (${zone.rangeLabel})` : ''} — {formatCurrency(zone.price)}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor={id('distance')} className="text-xs">
                                المسافة (كم)
                            </Label>
                            <Input
                                id={id('distance')}
                                type="number"
                                step="0.1"
                                min="0"
                                value={draft.distanceKm}
                                onChange={(e) => applyDistance(e.target.value)}
                                placeholder="اختياري"
                            />
                        </div>
                    </div>
                    {error('zone_id') && <p className="text-destructive text-xs">{error('zone_id')}</p>}

                    <div className="space-y-1">
                        <Label htmlFor={id('fee')} className="text-xs">
                            قيمة التوصيل
                        </Label>
                        <Input
                            id={id('fee')}
                            type="number"
                            step="0.01"
                            min="0"
                            value={canEditFee && draft.fee !== '' ? draft.fee : String(shipmentFee(draft, zones, canEditFee))}
                            onChange={(e) => set({ fee: e.target.value })}
                            disabled={!canEditFee}
                        />
                        <p className="text-muted-foreground text-xs">
                            {canEditFee ? 'تُملأ من الشريحة، وللإدارة تعديلها. شاملة الضريبة.' : 'تُحتسب من الشريحة المحددة — تعديلها للإدارة.'}
                        </p>
                        {error('fee') && <p className="text-destructive text-xs">{error('fee')}</p>}
                    </div>
                </>
            )}
        </div>
    );
}
