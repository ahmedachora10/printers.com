import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, CheckCircle2, DatabaseBackup, Loader2, Play, Terminal, XCircle } from 'lucide-react';
import { useMemo, useState, type ReactNode } from 'react';
import { useConsoleStream } from './use-console-stream';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'النشر', href: '/deployment' },
    { title: 'أوامر مفردة', href: '/deployment/commands' },
];

interface Task {
    key: string;
    label: string;
    display: string;
    group: string;
    hint: string;
    destructive: boolean;
    backup: boolean;
    /** يقبل ‎--branch=‎ فيُعرض له منتقي فرع. */
    branch: boolean;
}

interface Seeder {
    name: string;
    label: string;
    demo: boolean;
    runnable: boolean;
}

interface Props {
    groups: Record<string, string>;
    tasks: Task[];
    seeders: Seeder[];
    branches: { id: number; name: string }[];
    environment: {
        name: string;
        env: string;
        url: string;
        fakerInstalled: boolean;
        composerAvailable: boolean;
    };
    standalone: boolean;
}

const ALL_BRANCHES = 'all';
const DANGEROUS_WORD = 'تأكيد';

export default function DeploymentCommands({ groups, tasks, seeders, branches, environment, standalone }: Props) {
    const { output, running, result, errors, consoleRef, start } = useConsoleStream();

    const [selectedSeeders, setSelectedSeeders] = useState<string[]>([]);
    const [branch, setBranch] = useState<string>(ALL_BRANCHES);
    const [pending, setPending] = useState<Task | null>(null);
    const [typed, setTyped] = useState('');
    const [lastRun, setLastRun] = useState<string | null>(null);

    const demoSelected = useMemo(() => seeders.filter((seeder) => seeder.demo && selectedSeeders.includes(seeder.name)), [seeders, selectedSeeders]);

    const run = (task: Task) => {
        setLastRun(task.key);

        void start('/deployment/commands/run', {
            task: task.key,
            branch: branch === ALL_BRANCHES ? null : Number(branch),
            seeders: task.key === 'seed' ? selectedSeeders : [],
            demoConfirmed: demoSelected.length > 0,
        });
    };

    // الأمر الهيّن يُنفَّذ فوراً؛ وما يمسّ البيانات أو يُغلق الموقع يُسأل عنه،
    // ويُطلب في الزارع التجريبي أن تُكتب كلمة — الضغط بالخطأ وارد.
    const attempt = (task: Task) => {
        const needsWord = task.key === 'seed' && demoSelected.length > 0;

        if (task.destructive || needsWord) {
            setTyped('');
            setPending(task);

            return;
        }

        run(task);
    };

    const confirm = () => {
        const task = pending;
        setPending(null);
        setTyped('');

        if (task) {
            run(task);
        }
    };

    const blockedByWord = pending?.key === 'seed' && demoSelected.length > 0 && typed.trim() !== DANGEROUS_WORD;
    const seedBlocked = selectedSeeders.length === 0;

    return (
        <Shell standalone={standalone}>
            <Head title="أوامر مفردة" />

            <div className="flex flex-col gap-4 p-4">
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between gap-2">
                        <CardTitle className="flex items-center gap-2">
                            <Terminal className="size-5" />
                            أوامر مفردة — {environment.name}
                        </CardTitle>
                        <div className="flex items-center gap-2">
                            <Badge variant={environment.env === 'production' ? 'destructive' : 'secondary'}>{environment.env}</Badge>
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/deployment">
                                    <ArrowLeft className="size-4" />
                                    النشر الكامل
                                </Link>
                            </Button>
                        </div>
                    </CardHeader>
                    <CardContent className="text-muted-foreground text-xs">
                        كلُّ أمرٍ هنا يعمل وحده، والموقع يبقى مفتوحاً. ولا يبدأ أمرٌ ونشرةٌ تعمل، ولا تبدأ نشرةٌ وأمرٌ هنا يعمل.
                    </CardContent>
                </Card>

                {Object.entries(groups).map(([group, title]) => {
                    const rows = tasks.filter((task) => task.group === group);

                    if (rows.length === 0) {
                        return null;
                    }

                    return (
                        <Card key={group}>
                            <CardHeader>
                                <CardTitle className="text-base">{title}</CardTitle>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-3">
                                {rows.map((task) => (
                                    <div key={task.key} className="flex flex-col gap-2 border-b pb-3 last:border-0 last:pb-0">
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <div className="flex min-w-0 flex-col">
                                                <span className="flex items-center gap-2 text-sm font-medium">
                                                    {task.label}
                                                    {task.destructive && (
                                                        <Badge variant="destructive" className="gap-1 text-[10px]">
                                                            <AlertTriangle className="size-3" />
                                                            يُعدّل
                                                        </Badge>
                                                    )}
                                                    {task.backup && (
                                                        <Badge variant="outline" className="gap-1 text-[10px]">
                                                            <DatabaseBackup className="size-3" />
                                                            نسخة أولاً
                                                        </Badge>
                                                    )}
                                                </span>
                                                <code dir="ltr" className="text-muted-foreground truncate text-xs">
                                                    {task.display}
                                                </code>
                                                <span className="text-muted-foreground text-xs">{task.hint}</span>
                                            </div>

                                            <div className="flex items-center gap-2">
                                                {task.branch && (
                                                    <Select value={branch} onValueChange={setBranch} disabled={running}>
                                                        <SelectTrigger className="w-40">
                                                            <SelectValue />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value={ALL_BRANCHES}>كل الفروع</SelectItem>
                                                            {branches.map((item) => (
                                                                <SelectItem key={item.id} value={String(item.id)}>
                                                                    {item.name}
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                )}

                                                <Button
                                                    size="sm"
                                                    variant={task.destructive ? 'destructive' : 'outline'}
                                                    disabled={running || (task.key === 'seed' && seedBlocked)}
                                                    onClick={() => attempt(task)}
                                                >
                                                    {running && lastRun === task.key ? (
                                                        <Loader2 className="size-4 animate-spin" />
                                                    ) : (
                                                        <Play className="size-4" />
                                                    )}
                                                    تشغيل
                                                </Button>
                                            </div>
                                        </div>

                                        {/* منتقي الزارعات يسكن تحت زرّه، فلا معنى له في مكانٍ آخر. */}
                                        {task.key === 'seed' && (
                                            <div className="flex flex-col gap-2 rounded-md border p-3">
                                                {seeders.map((seeder) => (
                                                    <label
                                                        key={seeder.name}
                                                        className={
                                                            seeder.runnable
                                                                ? 'flex cursor-pointer items-center gap-3'
                                                                : 'flex items-center gap-3 opacity-50'
                                                        }
                                                    >
                                                        <Checkbox
                                                            checked={selectedSeeders.includes(seeder.name)}
                                                            disabled={running || !seeder.runnable}
                                                            onCheckedChange={(checked) =>
                                                                setSelectedSeeders((current) =>
                                                                    checked === true
                                                                        ? [...current, seeder.name]
                                                                        : current.filter((item) => item !== seeder.name),
                                                                )
                                                            }
                                                        />
                                                        <span className="flex flex-1 items-center justify-between gap-2">
                                                            <span className="text-sm">{seeder.label}</span>
                                                            {seeder.demo && (
                                                                <Badge variant="destructive" className="gap-1 text-[10px]">
                                                                    <AlertTriangle className="size-3" />
                                                                    بيانات تجريبية
                                                                </Badge>
                                                            )}
                                                        </span>
                                                    </label>
                                                ))}

                                                {seedBlocked && <p className="text-muted-foreground text-xs">اختر زارعاً واحداً على الأقل.</p>}

                                                {demoSelected.length > 0 && !environment.fakerInstalled && environment.composerAvailable && (
                                                    <p className="text-muted-foreground text-xs">
                                                        ستُثبَّت حزم التطوير أولاً (<code dir="ltr">composer install</code> بدون{' '}
                                                        <code dir="ltr">--no-dev</code>)، ثم يُشغَّل الزارع في عمليةٍ جديدة.
                                                    </p>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                ))}
                            </CardContent>
                        </Card>
                    );
                })}

                {errors.length > 0 && (
                    <div className="border-destructive/50 bg-destructive/10 text-destructive flex flex-col gap-1 rounded-md border p-3 text-sm">
                        {errors.map((error) => (
                            <span key={error}>{error}</span>
                        ))}
                    </div>
                )}

                {(output || running) && (
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle className="text-sm">المخرجات</CardTitle>
                            {result === 'success' && (
                                <span className="flex items-center gap-1.5 text-sm text-emerald-600">
                                    <CheckCircle2 className="size-4" /> تم
                                </span>
                            )}
                            {result === 'failure' && (
                                <span className="text-destructive flex items-center gap-1.5 text-sm">
                                    <XCircle className="size-4" /> فشل
                                </span>
                            )}
                        </CardHeader>
                        <CardContent>
                            {/* مخرجات الطرفية تُصفّ بالنقاط من اليسار، فتُقرأ ltr وإن كانت عربية. */}
                            <pre
                                ref={consoleRef}
                                dir="ltr"
                                className="max-h-[28rem] overflow-auto rounded-md bg-zinc-950 p-4 text-left font-mono text-xs leading-relaxed text-zinc-100"
                            >
                                {output || 'يبدأ التنفيذ…'}
                            </pre>
                        </CardContent>
                    </Card>
                )}
            </div>

            <Dialog open={pending !== null} onOpenChange={(open) => !open && setPending(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <AlertTriangle className="size-4" />
                            {pending?.label}
                        </DialogTitle>
                        <DialogDescription>
                            سيُنفَّذ على {environment.env} الآن: <code dir="ltr">{pending?.display}</code>
                        </DialogDescription>
                    </DialogHeader>

                    <p className="text-sm">{pending?.hint}</p>

                    {pending?.backup && <p className="text-muted-foreground text-xs">تُؤخذ نسخةٌ احتياطية لقاعدة البيانات قبله تلقائياً.</p>}

                    {pending?.key === 'seed' && demoSelected.length > 0 && (
                        <div className="flex flex-col gap-2">
                            <div className="border-destructive/50 bg-destructive/10 text-destructive flex items-start gap-2 rounded-md border p-3 text-xs">
                                <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                                <span>
                                    ستُزرع بياناتٌ وهمية ({demoSelected.map((seeder) => seeder.label).join('، ')}). اكتب «{DANGEROUS_WORD}» للمتابعة.
                                </span>
                            </div>
                            <input
                                value={typed}
                                onChange={(event) => setTyped(event.target.value)}
                                placeholder={DANGEROUS_WORD}
                                className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                            />
                        </div>
                    )}

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setPending(null)}>
                            إلغاء
                        </Button>
                        <Button variant="destructive" disabled={blockedByWord} onClick={confirm}>
                            نفّذ
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </Shell>
    );
}

/**
 * حاملُ المفتاح قد يكون زائراً بلا حساب، والقالب المعتاد يبني قائمةً من
 * مستخدمٍ قد لا يوجد.
 */
function Shell({ standalone, children }: { standalone: boolean; children: ReactNode }) {
    if (standalone) {
        return <div className="bg-muted/40 min-h-svh">{children}</div>;
    }

    return <AppLayout breadcrumbs={breadcrumbs}>{children}</AppLayout>;
}
