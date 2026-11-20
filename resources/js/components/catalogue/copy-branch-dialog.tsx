import categoryRoutes from '@/actions/App/Http/Controllers/CatalogCategoryController';
import { errorMessage, ReportView } from '@/components/import/import-dialog';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { postFormData } from '@/lib/upload';
import { type CatalogueBranchOption } from '@/types/catalogue';
import { type ImportReport } from '@/types/import';
import { router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';

/**
 * «نسخ من فرع» (super admin): one branch's own categories, services and prices
 * become the target branch's independent rows. Preview first — the server runs
 * the real copy and rolls it back — then confirm.
 */
export default function CopyBranchDialog({
    open,
    onOpenChange,
    branches,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    branches: CatalogueBranchOption[];
}) {
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');
    const [report, setReport] = useState<ImportReport | null>(null);
    const [done, setDone] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (open) {
            setReport(null);
            setDone(false);
            setError(null);
        }
    }, [open]);

    async function run(dryRun: boolean) {
        setBusy(true);
        setError(null);

        try {
            setReport(await postFormData<ImportReport>(categoryRoutes.copy.url(), { from, to, dry_run: dryRun ? 1 : 0 }));
            setDone(!dryRun);
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setBusy(false);
        }
    }

    function close(next: boolean) {
        if (busy) return;
        if (!next && done) router.reload();
        onOpenChange(next);
    }

    const pick = (setter: (v: string) => void) => (value: string) => {
        setter(value);
        setReport(null);
    };

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent className="max-h-[90vh] gap-4 overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>نسخ دليل فرع إلى فرع آخر</DialogTitle>
                    <DialogDescription>
                        تُنسخ فئات فرع المصدر وخدماته وأسعاره الخاصة إلى الفرع الهدف نسخةً مستقلة. ما يوجد بنفس الاسم تُحدَّث أسعاره، ولا يُحذف شيء.
                    </DialogDescription>
                </DialogHeader>

                {error && (
                    <div className="border-destructive/30 bg-destructive/10 text-destructive flex items-start gap-2 rounded-md border p-3 text-sm">
                        <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                        <span>{error}</span>
                    </div>
                )}

                {!done && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        <BranchSelect id="copy-from" label="من فرع" value={from} onChange={pick(setFrom)} branches={branches} />
                        <BranchSelect
                            id="copy-to"
                            label="إلى فرع"
                            value={to}
                            onChange={pick(setTo)}
                            branches={branches.filter((b) => String(b.id) !== from)}
                        />
                    </div>
                )}

                {report &&
                    (report.totalRows === 0 ? (
                        <p className="rounded-md border border-amber-500/30 bg-amber-500/10 p-3 text-sm text-amber-700 dark:text-amber-400">
                            لا يملك فرع المصدر فئات أو أسعارًا خاصة به لنسخها.
                        </p>
                    ) : (
                        <ReportView report={report} done={done} />
                    ))}

                <DialogFooter className="gap-2 sm:justify-start">
                    {busy ? (
                        <Button disabled>
                            <Loader2 className="size-4 animate-spin" /> يرجى الانتظار…
                        </Button>
                    ) : done ? (
                        <Button onClick={() => close(false)}>إغلاق</Button>
                    ) : report && report.totalRows > 0 ? (
                        <Button onClick={() => run(false)}>
                            <CheckCircle2 className="size-4" /> تأكيد النسخ
                        </Button>
                    ) : (
                        <Button onClick={() => run(true)} disabled={!from || !to}>
                            معاينة قبل النسخ
                        </Button>
                    )}
                    {!done && (
                        <Button variant="outline" onClick={() => close(false)} disabled={busy}>
                            إلغاء
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function BranchSelect({
    id,
    label,
    value,
    onChange,
    branches,
}: {
    id: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
    branches: CatalogueBranchOption[];
}) {
    return (
        <div className="space-y-1">
            <Label htmlFor={id}>{label}</Label>
            <Select value={value} onValueChange={onChange}>
                <SelectTrigger id={id}>
                    <SelectValue placeholder="اختر فرعًا" />
                </SelectTrigger>
                <SelectContent>
                    {branches.map((branch) => (
                        <SelectItem key={branch.id} value={String(branch.id)}>
                            {branch.name}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}
