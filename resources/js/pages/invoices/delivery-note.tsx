import { ThermalBranchHeader } from '@/components/invoices/print-header';
import { formatCurrency, formatDateTime, formatQty } from '@/lib/utils';
import { type PosBranch } from '@/types/pos';
import { Head } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { useEffect } from 'react';

/**
 * تاسك 93 — «بيان توصيل»: الورقة التي تُسلَّم للسائق.
 *
 * تحمل ما يوصله إلى الباب ويُسلّم به الطلب، **ولا تحمل سعر بندٍ ولا عمولة**:
 * السائق طرفٌ خارجيّ لا يرى ما باع به المركز. والخادم لا يرسل تلك الأرقام
 * أصلاً، فالحجب في المصدر لا في العرض.
 *
 * صفحةٌ عارية خارج قالب التطبيق كسائر أوراق الطباعة، فلا تحتاج حيلة إخفاءٍ
 * في `@media print` — لا شريط جانبيّ يُخفى.
 */
interface DeliveryNoteLine {
    name: string;
    notes: string | null;
    qty: number;
}

interface DeliveryNote {
    invoiceNumber: string;
    createdAt: string | null;
    deliveryAt: string | null;
    customerName: string | null;
    customerPhone: string | null;
    address: string | null;
    zoneName: string | null;
    distanceKm: number | null;
    providerName: string | null;
    providerPhone: string | null;
    shippingFee: number;
    notes: string | null;
    lines: DeliveryNoteLine[];
}

interface Props {
    note: DeliveryNote;
    branch: PosBranch;
}

function Row({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex justify-between gap-3">
            <span className="text-neutral-500">{label}</span>
            <span className="text-end font-medium">{value}</span>
        </div>
    );
}

export default function DeliveryNote({ note, branch }: Props) {
    useEffect(() => {
        const timer = setTimeout(() => window.print(), 300);
        return () => clearTimeout(timer);
    }, []);

    return (
        <div dir="rtl" className="mx-auto max-w-sm bg-white p-4 font-sans text-sm text-black">
            <Head title={`بيان توصيل ${note.invoiceNumber}`} />

            <div className="mb-4 flex items-center justify-end print:hidden">
                <button
                    type="button"
                    onClick={() => window.print()}
                    className="flex items-center gap-2 rounded-md bg-neutral-900 px-3 py-1.5 text-xs text-white"
                >
                    <Printer className="size-4" /> طباعة
                </button>
            </div>

            <ThermalBranchHeader branch={branch} />

            <h1 className="my-3 border-y border-black py-1.5 text-center text-base font-bold">بيان توصيل</h1>

            <div className="space-y-1 text-xs">
                <Row label="رقم الطلب" value={note.invoiceNumber} />
                {note.createdAt && <Row label="التاريخ" value={formatDateTime(note.createdAt)} />}
                {note.deliveryAt && <Row label="موعد التسليم" value={formatDateTime(note.deliveryAt)} />}
            </div>

            {/* العميل — الاسم والجوال والعنوان: ما يبلغ به السائق الباب. */}
            <div className="mt-3 space-y-1 border-t border-dashed border-black pt-2 text-xs">
                <p className="font-bold">بيانات العميل</p>
                {note.customerName && <Row label="الاسم" value={note.customerName} />}
                {note.customerPhone && (
                    <div className="flex justify-between gap-3">
                        <span className="text-neutral-500">الجوال</span>
                        <span dir="ltr" className="font-medium">
                            {note.customerPhone}
                        </span>
                    </div>
                )}
                {note.address && (
                    <div className="pt-1">
                        <p className="text-neutral-500">العنوان</p>
                        <p className="font-medium">{note.address}</p>
                    </div>
                )}
                {note.zoneName && <Row label="المنطقة" value={note.zoneName} />}
                {note.distanceKm !== null && <Row label="المسافة" value={`${formatQty(note.distanceKm)} كم`} />}
            </div>

            {/* البنود بأسمائها وكمّياتها وحدها — بلا سعرٍ ولا إجمالي. */}
            <div className="mt-3 border-t border-dashed border-black pt-2">
                <p className="mb-1 text-xs font-bold">محتويات الطلب</p>
                <table className="w-full text-xs">
                    <thead>
                        <tr className="border-b border-black">
                            <th className="py-1 text-start font-semibold">البند</th>
                            <th className="py-1 text-end font-semibold">الكمية</th>
                        </tr>
                    </thead>
                    <tbody>
                        {note.lines.map((line, i) => (
                            <tr key={i} className="border-b border-dashed border-neutral-300">
                                <td className="py-1">
                                    {line.name}
                                    {line.notes && <span className="block text-[10px] text-neutral-500">{line.notes}</span>}
                                </td>
                                <td className="py-1 text-end">{formatQty(line.qty)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="mt-3 space-y-1 border-t border-dashed border-black pt-2 text-xs">
                <p className="font-bold">التوصيل</p>
                {note.providerName && <Row label="السائق / الشركة" value={note.providerName} />}
                {note.providerPhone && (
                    <div className="flex justify-between gap-3">
                        <span className="text-neutral-500">جواله</span>
                        <span dir="ltr" className="font-medium">
                            {note.providerPhone}
                        </span>
                    </div>
                )}
                <div className="mt-1 flex justify-between border-t border-black pt-1 text-sm font-bold">
                    <span>قيمة التوصيل</span>
                    <span>{note.shippingFee > 0 ? formatCurrency(note.shippingFee) : 'مجاني'}</span>
                </div>
            </div>

            {/* ملاحظة العميل تفيد السائق: رقم الطابق، بوابة، وقت مناسب… */}
            {note.notes && (
                <div className="mt-3 border-t border-dashed border-black pt-2 text-xs">
                    <p className="font-bold">ملاحظات</p>
                    <p className="whitespace-pre-line">{note.notes}</p>
                </div>
            )}

            <div className="mt-6 flex justify-between gap-4 text-xs">
                <div className="flex-1">
                    <p className="text-neutral-500">توقيع المستلم</p>
                    <div className="mt-6 border-t border-black" />
                </div>
                <div className="flex-1">
                    <p className="text-neutral-500">توقيع السائق</p>
                    <div className="mt-6 border-t border-black" />
                </div>
            </div>
        </div>
    );
}
