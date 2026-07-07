import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export interface InvoiceCustomerFormData {
    full_name: string;
    phone: string;
    tax_number: string;
}

export type InvoiceCustomerErrors = Partial<Record<keyof InvoiceCustomerFormData, string>>;

interface Props {
    data: InvoiceCustomerFormData;
    onChange: (field: keyof InvoiceCustomerFormData, value: string) => void;
    errors: InvoiceCustomerErrors;
    disabled?: boolean;
    /** Unique prefix so field ids/labels don't collide when several instances render. */
    idPrefix: string;
    autoFocus?: boolean;
}

/**
 * Lightweight name / phone / tax-number fields for the invoice-scoped customer
 * edit (invoices.service.update-customer). Presentational only — the parent owns
 * the form state and submission, so it works both inline (review queue) and inside
 * a modal (invoices list).
 */
export default function InvoiceCustomerFields({ data, onChange, errors, disabled, idPrefix, autoFocus }: Props) {
    return (
        <div className="space-y-3">
            <div className="space-y-1">
                <Label htmlFor={`${idPrefix}-full-name`} className="text-xs">
                    الاسم
                </Label>
                <Input
                    id={`${idPrefix}-full-name`}
                    value={data.full_name}
                    onChange={(e) => onChange('full_name', e.target.value)}
                    placeholder="اسم العميل"
                    disabled={disabled}
                    autoFocus={autoFocus}
                    aria-label="اسم العميل"
                />
                {errors.full_name && <p className="text-xs text-destructive">{errors.full_name}</p>}
            </div>
            <div className="space-y-1">
                <Label htmlFor={`${idPrefix}-phone`} className="text-xs">
                    رقم الجوال
                </Label>
                <Input
                    id={`${idPrefix}-phone`}
                    value={data.phone}
                    onChange={(e) => onChange('phone', e.target.value)}
                    placeholder="05XXXXXXXX"
                    dir="ltr"
                    inputMode="tel"
                    disabled={disabled}
                    aria-label="رقم جوال العميل"
                />
                {errors.phone && <p className="text-xs text-destructive">{errors.phone}</p>}
            </div>
            <div className="space-y-1">
                <Label htmlFor={`${idPrefix}-tax-number`} className="text-xs">
                    الرقم الضريبي
                </Label>
                <Input
                    id={`${idPrefix}-tax-number`}
                    value={data.tax_number}
                    onChange={(e) => onChange('tax_number', e.target.value)}
                    placeholder="15 رقماً (اختياري)"
                    dir="ltr"
                    inputMode="numeric"
                    maxLength={15}
                    disabled={disabled}
                    aria-label="الرقم الضريبي للعميل"
                />
                {errors.tax_number && <p className="text-xs text-destructive">{errors.tax_number}</p>}
            </div>
        </div>
    );
}
