import { type ClassValue, clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export const formatNumber = (num: number): string => {
    return new Intl.NumberFormat('en-US').format(num);
};

export const formatCurrency = (amount: number, currency = 'SAR'): string => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency,
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(amount);
};

/**
 * كمية مخزون: عشرية منذ تاسك 51 لكن أكثرها أعداد صحيحة. تُكتب كما تُقرأ — «3»
 * و«0.5» و«12.25» لا «3.00». مطابقة لـ App\Support\Quantity على الخادم.
 */
export const formatQty = (qty: number): string => {
    return String(Math.round((qty + Number.EPSILON) * 100) / 100);
};

/** Customer-facing money: `1,234.50 ر.س` (the catalogue / price-list style). */
export const formatSar = (amount: number): string => {
    return `${amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ر.س`;
};

export const formatDate = (date: string | Date, locale: string = 'ar'): string => {
    const dateObj = typeof date === 'string' ? new Date(date) : date;
    return new Intl.DateTimeFormat(locale === 'ar' ? 'ar-u-nu-latn' : 'en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    }).format(dateObj);
};

const pad = (n: number): string => String(n).padStart(2, '0');

/** التاريخ بالصيغة المعتمدة في النظام: DD/MM/YYYY بأرقام لاتينية. */
export const formatDateNumeric = (date: string | Date): string => {
    const d = typeof date === 'string' ? new Date(date) : date;
    return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()}`;
};

/** موعد بتاريخ ووقت: `DD/MM/YYYY — HH:MM` (موعد التسليم يُعرض بهذه الصيغة). */
export const formatDateTimeNumeric = (date: string | Date): string => {
    const d = typeof date === 'string' ? new Date(date) : date;
    return `${formatDateNumeric(d)} — ${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

export const formatTime = (date: string | Date, locale: string = 'ar'): string => {
    const dateObj = typeof date === 'string' ? new Date(date) : date;
    return new Intl.DateTimeFormat(locale === 'ar' ? 'ar-u-nu-latn' : 'en-US', {
        hour: '2-digit',
        minute: '2-digit',
    }).format(dateObj);
};

export const formatDateTime = (date: string | Date, locale: string = 'ar'): string => {
    const dateObj = typeof date === 'string' ? new Date(date) : date;
    return new Intl.DateTimeFormat(locale === 'ar' ? 'ar-u-nu-latn' : 'en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(dateObj);
};