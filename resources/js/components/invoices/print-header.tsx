import { type InvoiceBranch } from '@/types/invoice';
import { type PosBranch } from '@/types/pos';
import { Printer } from 'lucide-react';
import { useEffect } from 'react';

/** الحد الأدنى من بيانات الفرع الذي تحتاجه الترويسة — يغطّي فاتورة محفوظة وفاتورة نقطة بيع. */
export type PrintBranch = InvoiceBranch | PosBranch;

/**
 * بيانات الفرع تحت اسمه: العنوان ثم رقم الجوال ثم الرقم الضريبي، بنفس هذا
 * الترتيب في كل ورقة تُطبع (A4 وحراري ونقطة بيع) حتى لا تختلف ترويستان.
 *
 * الأرقام داخل عناصر dir="ltr" مستقلة حتى لا تنعكس خانة الآحاد إلى اليسار،
 * والتسمية العربية تبقى على اليمين.
 */
export function BranchIdentity({ branch, className = '' }: { branch: PrintBranch; className?: string }) {
    return (
        <div className={className}>
            {branch.address && <p>{branch.address}</p>}
            {branch.phone && (
                <p>
                    رقم الجوال : <span dir="ltr">{branch.phone}</span>
                </p>
            )}
            {branch.taxNumber && (
                <p>
                    الرقم الضريبي: <span dir="ltr">{branch.taxNumber}</span>
                </p>
            )}
        </div>
    );
}

/**
 * ترويسة الإيصال الحراري: الشعار في الأعلى وسطاً بحد أقصى 40px ارتفاعاً حتى لا
 * يبتلع عرض الـ 80mm، ثم اسم الفرع وبياناته. الفرع بلا شعار يكتفي باسمه — لا
 * صورة مكسورة ولا فراغ محجوز.
 */
export function ThermalBranchHeader({ branch }: { branch: PrintBranch }) {
    const logoUrl = 'logoUrl' in branch ? branch.logoUrl : null;

    return (
        <>
            {logoUrl && <img src={logoUrl} alt="" className="mx-auto mb-1 h-10 w-auto object-contain" />}
            <h1 className="text-base font-bold">{branch.name ?? 'مركز الناسخ للطباعة'}</h1>
            <BranchIdentity branch={branch} className="text-xs" />
        </>
    );
}

/** زرّ «طباعة» فوق الورقة (لا يُطبع)، ويفتح نافذة الطباعة تلقائياً عند التحميل. */
export function PrintToolbar() {
    useEffect(() => {
        const timer = setTimeout(() => window.print(), 400);
        return () => clearTimeout(timer);
    }, []);

    return (
        <div className="mb-4 flex items-center justify-end gap-2 print:hidden">
            <button
                type="button"
                onClick={() => window.print()}
                className="bg-primary text-primary-foreground flex items-center gap-1 rounded-md px-3 py-1.5 text-sm"
            >
                <Printer className="size-4" /> طباعة
            </button>
        </div>
    );
}
