import { Button } from '@/components/ui/button';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { Download } from 'lucide-react';

/**
 * زرّ «تصدير Excel» المشترك بين صفحات التقارير. مراجع الحسابات (تاسك 125) يطّلع
 * على التقرير ولا يصدّره — ومسارات التصدير خارج مجموعته أصلاً — فلا يُعرض له.
 */
export function ReportExportButton({ href, disabled }: { href: string; disabled?: boolean }) {
    if (usePage<SharedData>().props.auth.role === 'auditor') {
        return null;
    }

    return (
        <Button asChild variant="outline" disabled={disabled}>
            <a href={href}>
                <Download className="size-4" /> تصدير Excel
            </a>
        </Button>
    );
}
