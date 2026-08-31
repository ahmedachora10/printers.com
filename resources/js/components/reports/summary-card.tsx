import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import * as React from 'react';

/**
 * بطاقة رقمٍ واحد أعلى التقرير: أيقونة وعنوان وقيمة، وتلميحٌ اختياري تحتها.
 *
 * كانت معرَّفة محلياً في كل صفحة تقرير بالنصّ نفسه؛ هذه نسختها الواحدة.
 */
export function SummaryCard({
    icon,
    label,
    value,
    valueClass,
    hint,
}: {
    icon: React.ReactNode;
    label: string;
    value: string;
    valueClass?: string;
    hint?: string;
}) {
    return (
        <Card className="min-w-0">
            <CardHeader className="pb-2">
                <CardTitle className="text-muted-foreground flex items-center gap-2 text-sm font-medium">
                    <span className="shrink-0">{icon}</span>
                    <span className="truncate">{label}</span>
                </CardTitle>
            </CardHeader>
            <CardContent>
                <p className={`truncate text-xl font-bold sm:text-2xl ${valueClass ?? ''}`}>{value}</p>
                {hint && <p className="text-muted-foreground mt-1 truncate text-sm tabular-nums">{hint}</p>}
            </CardContent>
        </Card>
    );
}
