import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { postFormData, UploadError } from '@/lib/upload';
import { cn } from '@/lib/utils';
import { type ImportReport, type ImportRowResult, type ImportScope } from '@/types/import';
import {
    AlertTriangle,
    ArrowRight,
    Building2,
    CheckCircle2,
    Download,
    FileSpreadsheet,
    Loader2,
    Upload,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

/**
 * The one import dialog every Excel import screen uses: pick a file, see what
 * it would do, confirm it.
 *
 * The old flow was a hidden `<input type="file">` posting straight into the
 * database — no scope shown, no result, and (because the Arabic headings never
 * matched) no rows written either. Nothing on screen could tell those apart,
 * so the fix is as much about showing the outcome as about producing one: the
 * preview step reports what will happen, the final step what did.
 *
 * The dialog knows nothing about catalogues: the endpoints hand it labelled
 * counters and rows, so another import screen needs no changes here.
 */
interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: string;
    previewUrl: string;
    commitUrl: string;
    templateUrl?: string;
    /** Fields that ride along with the file on both requests (the branch, here). */
    payload?: Record<string, string | number | null>;
    scope?: ImportScope;
    /** Called once rows were actually written, so the page can refresh itself. */
    onImported?: () => void;
}

type Stage = 'upload' | 'previewing' | 'preview' | 'committing' | 'done';

const ACTION_BADGE: Record<ImportRowResult['action'], { label: string; className: string }> = {
    create: { label: 'إضافة', className: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' },
    update: { label: 'تحديث', className: 'bg-sky-500/10 text-sky-600 dark:text-sky-400' },
    skip: { label: 'تجاهل', className: 'bg-amber-500/10 text-amber-600 dark:text-amber-400' },
    ok: { label: 'بلا تغيير', className: 'bg-muted text-muted-foreground' },
};

const TONE_CLASS = {
    success: 'border-emerald-500/30 bg-emerald-500/5 text-emerald-600 dark:text-emerald-400',
    info: 'border-sky-500/30 bg-sky-500/5 text-sky-600 dark:text-sky-400',
    warning: 'border-amber-500/30 bg-amber-500/5 text-amber-600 dark:text-amber-400',
};

export default function ImportDialog({
    open,
    onOpenChange,
    title,
    description,
    previewUrl,
    commitUrl,
    templateUrl,
    payload = {},
    scope,
    onImported,
}: Props) {
    const [stage, setStage] = useState<Stage>('upload');
    const [file, setFile] = useState<File | null>(null);
    const [dragging, setDragging] = useState(false);
    const [progress, setProgress] = useState(0);
    const [report, setReport] = useState<ImportReport | null>(null);
    const [error, setError] = useState<string | null>(null);
    const inputRef = useRef<HTMLInputElement>(null);
    const imported = useRef(false);

    useEffect(() => {
        if (!open) {
            return;
        }

        setStage('upload');
        setFile(null);
        setProgress(0);
        setReport(null);
        setError(null);
        imported.current = false;
    }, [open]);

    const busy = stage === 'previewing' || stage === 'committing';

    function handleOpenChange(next: boolean) {
        if (busy) {
            return; // a request is in flight; closing now would hide its outcome
        }

        if (!next && imported.current) {
            onImported?.();
        }

        onOpenChange(next);
    }

    function pickFile(selected: File | null | undefined) {
        if (!selected) {
            return;
        }

        setError(null);
        setFile(selected);
    }

    async function runPreview() {
        if (!file) {
            return;
        }

        setStage('previewing');
        setProgress(0);
        setError(null);

        try {
            setReport(await postFormData<ImportReport>(previewUrl, { ...payload, file }, setProgress));
            setStage('preview');
        } catch (e) {
            setError(errorMessage(e));
            setStage('upload');
        }
    }

    async function runCommit() {
        if (!report) {
            return;
        }

        setStage('committing');
        setProgress(100);
        setError(null);

        try {
            setReport(
                await postFormData<ImportReport>(commitUrl, {
                    ...payload,
                    token: report.token ?? null,
                    fileName: report.fileName ?? null,
                }),
            );
            imported.current = true;
            setStage('done');
        } catch (e) {
            setError(errorMessage(e));
            setStage('preview');
        }
    }

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent className="max-h-[90vh] gap-4 overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>

                <Stepper stage={stage} />

                {error && (
                    <div className="flex items-start gap-2 rounded-md border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive">
                        <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                        <span>{error}</span>
                    </div>
                )}

                {stage === 'upload' && (
                    <div className="space-y-4">
                        {scope && <ScopeField scope={scope} />}

                        {templateUrl && (
                            <div className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-dashed border-border bg-muted/30 p-3">
                                <p className="text-xs text-muted-foreground">
                                    لست متأكدًا من الأعمدة؟ نزّل النموذج الجاهز واملأه ثم ارفعه.
                                </p>
                                <Button variant="outline" size="sm" asChild>
                                    <a href={templateUrl}>
                                        <Download className="size-4" /> تنزيل النموذج
                                    </a>
                                </Button>
                            </div>
                        )}

                        {file ? (
                            <div className="flex items-center gap-3 rounded-lg border border-border bg-card p-4">
                                <FileSpreadsheet className="size-8 shrink-0 text-primary" />
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-medium">{file.name}</p>
                                    <p className="text-xs text-muted-foreground">{formatSize(file.size)}</p>
                                </div>
                                <Button variant="ghost" size="icon" onClick={() => setFile(null)} aria-label="إزالة الملف">
                                    <X className="size-4" />
                                </Button>
                            </div>
                        ) : (
                            <div
                                role="button"
                                tabIndex={0}
                                onClick={() => inputRef.current?.click()}
                                onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && inputRef.current?.click()}
                                onDragOver={(e) => {
                                    e.preventDefault();
                                    setDragging(true);
                                }}
                                onDragLeave={() => setDragging(false)}
                                onDrop={(e) => {
                                    e.preventDefault();
                                    setDragging(false);
                                    pickFile(e.dataTransfer.files?.[0]);
                                }}
                                className={cn(
                                    'flex cursor-pointer flex-col items-center gap-2 rounded-lg border-2 border-dashed border-border p-8 text-center transition-colors hover:border-primary/50 hover:bg-muted/40',
                                    dragging && 'border-primary bg-primary/5',
                                )}
                            >
                                <Upload className="size-8 text-muted-foreground" />
                                <p className="text-sm font-medium">اسحب الملف هنا أو اضغط للاختيار</p>
                                <p className="text-xs text-muted-foreground">
                                    الصيغ المقبولة: xlsx، xls، csv — بحد أقصى 10 ميغابايت
                                </p>
                            </div>
                        )}

                        <input
                            ref={inputRef}
                            type="file"
                            accept=".xlsx,.xls,.csv"
                            className="sr-only"
                            onChange={(e) => {
                                pickFile(e.target.files?.[0]);
                                e.target.value = '';
                            }}
                        />
                    </div>
                )}

                {stage === 'previewing' && <ProgressBar percent={progress} label="جارٍ رفع الملف وقراءة صفوفه…" />}
                {stage === 'committing' && <ProgressBar percent={100} label="جارٍ تنفيذ الاستيراد…" />}

                {(stage === 'preview' || stage === 'done') && report && (
                    <ReportView report={report} done={stage === 'done'} />
                )}

                <DialogFooter className="gap-2 sm:justify-start">
                    {stage === 'upload' && (
                        <>
                            <Button onClick={runPreview} disabled={!file}>
                                معاينة قبل الاستيراد
                            </Button>
                            <Button variant="outline" onClick={() => handleOpenChange(false)}>
                                إلغاء
                            </Button>
                        </>
                    )}

                    {busy && (
                        <Button disabled>
                            <Loader2 className="size-4 animate-spin" /> يرجى الانتظار…
                        </Button>
                    )}

                    {stage === 'preview' && report && (
                        <>
                            <Button onClick={runCommit} disabled={report.totalRows === report.skipped.length}>
                                <CheckCircle2 className="size-4" /> تأكيد الاستيراد
                            </Button>
                            <Button variant="outline" onClick={() => setStage('upload')}>
                                <ArrowRight className="size-4" /> اختيار ملف آخر
                            </Button>
                        </>
                    )}

                    {stage === 'done' && <Button onClick={() => handleOpenChange(false)}>إغلاق</Button>}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function ScopeField({ scope }: { scope: ImportScope }) {
    if (!scope.options) {
        return (
            <div className="flex items-center gap-2 rounded-md border border-primary/20 bg-primary/5 p-3 text-sm">
                <Building2 className="size-4 shrink-0 text-primary" />
                <span>
                    سيتم الاستيراد إلى <strong>{scope.pinnedLabel ?? 'فرعك'}</strong>.
                </span>
            </div>
        );
    }

    return (
        <div className="space-y-1">
            <Label htmlFor="import-scope">وجهة الاستيراد</Label>
            <Select value={scope.value} onValueChange={scope.onChange}>
                <SelectTrigger id="import-scope">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {scope.options.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            {scope.hint && <p className="text-xs text-muted-foreground">{scope.hint}</p>}
        </div>
    );
}

function ProgressBar({ percent, label }: { percent: number; label: string }) {
    return (
        <div className="space-y-2 py-6">
            <div className="flex items-center justify-between text-sm">
                <span className="flex items-center gap-2 text-muted-foreground">
                    <Loader2 className="size-4 animate-spin" /> {label}
                </span>
                <span className="tabular-nums text-muted-foreground">{percent}%</span>
            </div>
            <div className="h-2 overflow-hidden rounded-full bg-muted">
                <div
                    className={cn('h-full rounded-full bg-primary transition-all', percent >= 100 && 'animate-pulse')}
                    style={{ width: `${percent}%` }}
                />
            </div>
        </div>
    );
}

function ReportView({ report, done }: { report: ImportReport; done: boolean }) {
    const nothingToDo = report.totalRows === report.skipped.length;

    return (
        <div className="space-y-4">
            <div
                className={cn(
                    'flex items-start gap-2 rounded-md border p-3 text-sm',
                    done
                        ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400'
                        : nothingToDo
                          ? 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-400'
                          : 'border-border bg-muted/40 text-muted-foreground',
                )}
            >
                {done ? (
                    <CheckCircle2 className="mt-0.5 size-4 shrink-0" />
                ) : (
                    <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                )}
                <span>
                    {done
                        ? `تم الاستيراد بنجاح — ${report.totalRows} صفًا تمت معالجته.`
                        : nothingToDo
                          ? 'لا يوجد أي صف صالح في هذا الملف — راجع الأعمدة ثم أعد المحاولة.'
                          : `قرأنا ${report.totalRows} صفًا ولم نكتب شيئًا بعد. راجع الملخص ثم اضغط «تأكيد الاستيراد».`}
                </span>
            </div>

            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                {report.summary.map((tile) => (
                    <div key={tile.key} className={cn('rounded-lg border p-3', TONE_CLASS[tile.tone])}>
                        <p className="text-2xl font-semibold tabular-nums">{tile.value}</p>
                        <p className="text-xs">{tile.label}</p>
                    </div>
                ))}
            </div>

            {report.skipped.length > 0 && (
                <div className="space-y-2">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <p className="text-sm font-medium">صفوف متجاهَلة ({report.skipped.length})</p>
                        <Button variant="outline" size="sm" onClick={() => downloadSkipped(report)}>
                            <Download className="size-4" /> تنزيل قائمة المتجاهَل
                        </Button>
                    </div>
                    <RowTable rows={report.skipped} withReason />
                </div>
            )}

            {report.rows.length > 0 && (
                <div className="space-y-2">
                    <p className="text-sm font-medium">
                        {done ? 'تفاصيل الصفوف' : 'معاينة الصفوف'}
                        {report.totalRows > report.rows.length && (
                            <span className="text-muted-foreground"> — أول {report.rows.length} صف</span>
                        )}
                    </p>
                    <RowTable rows={report.rows} />
                </div>
            )}
        </div>
    );
}

function RowTable({ rows, withReason = false }: { rows: ImportRowResult[]; withReason?: boolean }) {
    return (
        <div className="max-h-56 overflow-auto rounded-md border border-border">
            <table className="w-full text-sm">
                <thead className="sticky top-0 bg-muted/80 text-xs text-muted-foreground backdrop-blur">
                    <tr>
                        <th className="px-3 py-2 text-start font-medium">الصف</th>
                        <th className="px-3 py-2 text-start font-medium">البند</th>
                        <th className="px-3 py-2 text-start font-medium">{withReason ? 'السبب' : 'الإجراء'}</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={`${row.row}-${row.label}`} className="border-t border-border/60">
                            <td className="px-3 py-2 tabular-nums text-muted-foreground">{row.row}</td>
                            <td className="px-3 py-2">{row.label}</td>
                            <td className="px-3 py-2">
                                {withReason ? (
                                    <span className="text-amber-600 dark:text-amber-400">{row.reason}</span>
                                ) : (
                                    <span className={cn('rounded px-2 py-0.5 text-xs', ACTION_BADGE[row.action].className)}>
                                        {ACTION_BADGE[row.action].label}
                                    </span>
                                )}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/** The skipped rows as a sheet the user can fix and re-upload. */
function downloadSkipped(report: ImportReport) {
    const csv = [
        ['رقم الصف', 'البند', 'السبب'],
        ...report.skipped.map((row) => [row.row, row.label, row.reason ?? '']),
    ]
        .map((row) => row.map((cell) => `"${String(cell).replace(/"/g, '""')}"`).join(','))
        .join('\r\n');

    // The BOM is what makes Excel open an Arabic CSV as UTF-8.
    const url = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' }));
    const link = document.createElement('a');

    link.href = url;
    link.download = `skipped-rows-${report.fileName ?? 'import'}.csv`;
    link.click();
    URL.revokeObjectURL(url);
}

function Stepper({ stage }: { stage: Stage }) {
    const steps = [
        { key: 'file', label: 'اختيار الملف', active: stage === 'upload', done: stage !== 'upload' },
        {
            key: 'preview',
            label: 'المعاينة',
            active: stage === 'previewing' || stage === 'preview',
            done: stage === 'committing' || stage === 'done',
        },
        { key: 'commit', label: 'التنفيذ', active: stage === 'committing', done: stage === 'done' },
    ];

    return (
        <div className="flex items-center gap-2 text-xs">
            {steps.map((step, index) => (
                <div key={step.key} className="flex flex-1 items-center gap-2">
                    <span
                        className={cn(
                            'flex size-6 shrink-0 items-center justify-center rounded-full border text-[11px] font-medium',
                            step.done
                                ? 'border-primary bg-primary text-primary-foreground'
                                : step.active
                                  ? 'border-primary text-primary'
                                  : 'border-border text-muted-foreground',
                        )}
                    >
                        {step.done ? '✓' : index + 1}
                    </span>
                    <span className={cn(step.active || step.done ? 'text-foreground' : 'text-muted-foreground')}>
                        {step.label}
                    </span>
                    {index < steps.length - 1 && <span className="h-px flex-1 bg-border" />}
                </div>
            ))}
        </div>
    );
}

function formatSize(bytes: number): string {
    return bytes < 1024 * 1024
        ? `${Math.max(1, Math.round(bytes / 1024))} كيلوبايت`
        : `${(bytes / (1024 * 1024)).toFixed(1)} ميغابايت`;
}

function errorMessage(e: unknown): string {
    if (e instanceof UploadError) {
        const first = Object.values(e.errors)[0]?.[0];

        return first ?? e.message;
    }

    return 'حدث خطأ غير متوقع أثناء معالجة الملف.';
}
