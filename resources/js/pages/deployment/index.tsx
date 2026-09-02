import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, GitBranch, Loader2, LockKeyhole, Play, ScanEye, ServerIcon, XCircle } from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'النشر', href: '/deployment' }];

interface Seeder {
    name: string;
    label: string;
    demo: boolean;
    /** زارعُ مصانعَ على خادمٍ بلا حزم تطوير: لا fake() هناك أصلاً. */
    runnable: boolean;
}

interface Props {
    environment: {
        name: string;
        env: string;
        url: string;
        php: string;
        phpBinary: string;
        phpBinaryVersion: string | null;
        phpBinarySource: string;
        phpBinaryOk: boolean;
        phpBinaryNote: string | null;
        database: string;
        branch: string | null;
        commit: string | null;
        committedAt: string | null;
    };
    seeders: Seeder[];
    preferences: {
        options: Record<string, boolean>;
        seeders: string[];
        branch: string | null;
    };
    history: {
        id: number;
        description: string;
        status: number | null;
        via: string | null;
        user: string | null;
        at: string | null;
    }[];
    /** حاملُ المفتاح قد يكون زائراً بلا حساب، فلا قائمة جانبية تُعرض له. */
    standalone: boolean;
    unlockedByToken: boolean;
}

/** الخطوات كما يراها المُشغِّل. الترتيب هو ترتيب التنفيذ نفسه، فتُقرأ كخطة. */
const STEPS: { key: string; title: string; hint: string }[] = [
    { key: 'maintenance', title: 'إغلاق الموقع أثناء النشر', hint: 'يُفتح تلقائياً بعد الانتهاء ولو تعثّرت خطوة' },
    { key: 'pull', title: 'سحب الكود من المستودع', hint: 'git pull --ff-only على الفرع المختار' },
    { key: 'composer', title: 'تحديث حزم composer', hint: 'install --no-dev مع تحسين المُحمِّل' },
    { key: 'backup', title: 'نسخة احتياطية لقاعدة البيانات', hint: 'قبل أي هجرة — لا يُنصح بإيقافها' },
    { key: 'migrate', title: 'تشغيل الهجرات', hint: 'migrate --force' },
    { key: 'seed', title: 'تشغيل الزارعات', hint: 'اختر أدناه ما يُزرع' },
    { key: 'health', title: 'فحص الموقع بعد الفتح', hint: 'قاعدة البيانات وصفحة /up' },
    { key: 'rollback', title: 'التراجع إن سقطت خطوة', hint: 'يُرجع الكود والأصول؛ الهجرات لا تُردّ تلقائياً' },
];

const DANGEROUS_WORD = 'تأكيد';

function readCookie(name: string): string {
    const match = document.cookie.split('; ').find((row) => row.startsWith(name + '='));

    return match ? decodeURIComponent(match.split('=').slice(1).join('=')) : '';
}

export default function DeploymentIndex({ environment, seeders, preferences, history, standalone, unlockedByToken }: Props) {
    const [options, setOptions] = useState<Record<string, boolean>>(() =>
        Object.fromEntries(STEPS.map((step) => [step.key, preferences.options[step.key] ?? true])),
    );
    const [selectedSeeders, setSelectedSeeders] = useState<string[]>(preferences.seeders);
    const [branch, setBranch] = useState(preferences.branch ?? '');
    const [output, setOutput] = useState('');
    const [running, setRunning] = useState(false);
    const [result, setResult] = useState<'success' | 'failure' | null>(null);
    const [errors, setErrors] = useState<string[]>([]);
    const [confirming, setConfirming] = useState(false);
    const [typed, setTyped] = useState('');

    const consoleRef = useRef<HTMLPreElement>(null);

    const demoSelected = useMemo(() => seeders.filter((seeder) => seeder.demo && selectedSeeders.includes(seeder.name)), [seeders, selectedSeeders]);

    // المخرجات تُلاحَق إلى آخرها ما دامت تُكتب.
    useEffect(() => {
        consoleRef.current?.scrollTo({ top: consoleRef.current.scrollHeight });
    }, [output]);

    // إغلاق التبويب أثناء النشر لا يُوقفه على الخادم، لكنه يُعمي صاحبه عنه.
    useEffect(() => {
        if (!running) {
            return;
        }

        const warn = (event: BeforeUnloadEvent) => event.preventDefault();
        window.addEventListener('beforeunload', warn);

        return () => window.removeEventListener('beforeunload', warn);
    }, [running]);

    const toggleStep = (key: string, checked: boolean) => setOptions((current) => ({ ...current, [key]: checked }));

    const toggleSeeder = (name: string, checked: boolean) =>
        setSelectedSeeders((current) => (checked ? [...current, name] : current.filter((item) => item !== name)));

    const start = async (dryRun: boolean) => {
        setRunning(true);
        setResult(null);
        setErrors([]);
        setOutput('');

        try {
            const response = await fetch('/deployment/run', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'text/plain, application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': readCookie('XSRF-TOKEN'),
                },
                body: JSON.stringify({
                    dryRun,
                    branch: branch.trim() || null,
                    options,
                    seeders: options.seed ? selectedSeeders : [],
                    demoConfirmed: demoSelected.length > 0,
                }),
            });

            if (!response.ok || !response.body) {
                const payload = await response.json().catch(() => null);

                setErrors(
                    payload?.errors
                        ? Object.values(payload.errors as Record<string, string[]>).flat()
                        : [payload?.message ?? 'تعذّر بدء النشر (' + response.status + ').'],
                );
                setResult('failure');

                return;
            }

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let text = '';

            for (;;) {
                const { done, value } = await reader.read();

                if (done) {
                    break;
                }

                text += decoder.decode(value, { stream: true });
                setOutput(text);
            }

            // ترويسة الردّ سبقت النشر كلَّه، فالحكم من السطر الختامي وحده.
            setResult(text.includes('== اكتمل النشر ==') ? 'success' : 'failure');
        } catch (error) {
            setErrors([error instanceof Error ? error.message : 'انقطع الاتصال بالخادم.']);
            setResult('failure');
        } finally {
            setRunning(false);
        }
    };

    const confirmAndStart = () => {
        setConfirming(false);
        setTyped('');
        void start(false);
    };

    const blockedByWord = demoSelected.length > 0 && typed.trim() !== DANGEROUS_WORD;

    return (
        <Shell standalone={standalone}>
            <Head title="النشر" />

            <div className="flex flex-col gap-4 p-4">
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between gap-2">
                        <CardTitle className="flex items-center gap-2">
                            <ServerIcon className="size-5" />
                            نشر {environment.name}
                        </CardTitle>
                        <div className="flex items-center gap-2">
                            <Badge variant={environment.env === 'production' ? 'destructive' : 'secondary'}>{environment.env}</Badge>
                            {unlockedByToken && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={running}
                                    onClick={() => router.delete('/deployment/unlock')}
                                    title="إنهاء الجلسة المفتوحة بالمفتاح"
                                >
                                    <LockKeyhole className="size-4" />
                                    أغلق
                                </Button>
                            )}
                        </div>
                    </CardHeader>
                    <CardContent className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                        <Detail label="العنوان" value={environment.url} />
                        <Detail label="الفرع الحالي" value={environment.branch ?? '—'} />
                        <Detail label="آخر إصدار" value={environment.commit ? environment.commit + ' · ' + (environment.committedAt ?? '') : '—'} />
                        <Detail label="PHP / قاعدة البيانات" value={environment.php + ' · ' + environment.database} />
                    </CardContent>

                    <CardContent className="pt-0">
                        {/* المُفسِّر الذي تُشغَّل به العمليات الفرعية — على cPanel قد يخالف الذي يخدم الصفحة. */}
                        <div
                            className={
                                environment.phpBinaryOk
                                    ? 'text-muted-foreground flex flex-wrap items-center gap-2 text-xs'
                                    : 'border-destructive/50 bg-destructive/10 text-destructive flex flex-wrap items-start gap-2 rounded-md border p-3 text-xs'
                            }
                        >
                            {!environment.phpBinaryOk && <AlertTriangle className="mt-0.5 size-4 shrink-0" />}
                            <span>
                                مُفسِّر العمليات الفرعية: <code dir="ltr">{environment.phpBinary}</code>
                                {environment.phpBinaryVersion && ' (' + environment.phpBinaryVersion + ')'} — {environment.phpBinarySource}
                            </span>
                            {environment.phpBinaryNote && <span>{environment.phpBinaryNote}</span>}
                        </div>
                    </CardContent>
                </Card>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>الخطوات</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-3">
                            {STEPS.map((step) => (
                                <label key={step.key} className="flex cursor-pointer items-start gap-3">
                                    <Checkbox
                                        checked={options[step.key]}
                                        onCheckedChange={(checked) => toggleStep(step.key, checked === true)}
                                        disabled={running}
                                        className="mt-0.5"
                                    />
                                    <span className="flex flex-col">
                                        <span className="text-sm font-medium">{step.title}</span>
                                        <span className="text-muted-foreground text-xs">{step.hint}</span>
                                    </span>
                                </label>
                            ))}

                            <div className="flex flex-col gap-1.5 pt-2">
                                <Label htmlFor="branch" className="text-xs">
                                    الفرع (اتركه فارغاً للفرع الحالي على الخادم)
                                </Label>
                                <Input
                                    id="branch"
                                    value={branch}
                                    onChange={(event) => setBranch(event.target.value)}
                                    placeholder={environment.branch ?? 'main'}
                                    disabled={running || !options.pull}
                                    className="max-w-xs"
                                />
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>الزارعات</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-3">
                            {!options.seed && <p className="text-muted-foreground text-xs">خطوة الزارعات موقوفة أعلاه.</p>}

                            {seeders.map((seeder) => (
                                <label
                                    key={seeder.name}
                                    className={seeder.runnable ? 'flex cursor-pointer items-center gap-3' : 'flex items-center gap-3 opacity-50'}
                                    title={seeder.runnable ? undefined : 'غير متاح على هذا الخادم'}
                                >
                                    <Checkbox
                                        checked={selectedSeeders.includes(seeder.name)}
                                        onCheckedChange={(checked) => toggleSeeder(seeder.name, checked === true)}
                                        disabled={running || !options.seed || !seeder.runnable}
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

                            {seeders.some((seeder) => !seeder.runnable) && (
                                <p className="text-muted-foreground text-xs">
                                    زارعات البيانات التجريبية مُعطَّلة هنا: حزم التطوير غير مثبّتة على الخادم (
                                    <code dir="ltr">composer install --no-dev</code>)، ودالّة <code dir="ltr">fake()</code> لا توجد بدونها.
                                </p>
                            )}

                            {demoSelected.length > 0 && (
                                <div className="border-destructive/50 bg-destructive/10 text-destructive flex items-start gap-2 rounded-md border p-3 text-xs">
                                    <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                                    <span>
                                        اخترتَ زارعاً يخلق بياناتٍ وهمية ({demoSelected.map((seeder) => seeder.label).join('، ')}). على فرعٍ عامل
                                        تختلط هذه بالبيانات الحقيقية ولا تُميَّز بعدها.
                                    </span>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <Button onClick={() => void start(true)} disabled={running} variant="outline">
                        <ScanEye className="size-4" />
                        عرض مجرّد
                    </Button>
                    <Button onClick={() => setConfirming(true)} disabled={running}>
                        {running ? <Loader2 className="size-4 animate-spin" /> : <Play className="size-4" />}
                        ابدأ النشر
                    </Button>

                    {result === 'success' && (
                        <span className="flex items-center gap-1.5 text-sm text-emerald-600">
                            <CheckCircle2 className="size-4" /> اكتمل
                        </span>
                    )}
                    {result === 'failure' && (
                        <span className="text-destructive flex items-center gap-1.5 text-sm">
                            <XCircle className="size-4" /> لم يكتمل
                        </span>
                    )}
                </div>

                {errors.length > 0 && (
                    <div className="border-destructive/50 bg-destructive/10 text-destructive flex flex-col gap-1 rounded-md border p-3 text-sm">
                        {errors.map((error) => (
                            <span key={error}>{error}</span>
                        ))}
                    </div>
                )}

                {(output || running) && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm">المخرجات</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {/* مخرجات الطرفية تُصفّ بالنقاط من اليسار، فتُقرأ ltr وإن كانت عربية. */}
                            <pre
                                ref={consoleRef}
                                dir="ltr"
                                className="max-h-[28rem] overflow-auto rounded-md bg-zinc-950 p-4 text-left font-mono text-xs leading-relaxed text-zinc-100"
                            >
                                {output || 'يبدأ النشر…'}
                            </pre>
                        </CardContent>
                    </Card>
                )}

                {history.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm">آخر عمليات النشر</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-2 text-sm">
                            {history.map((entry) => (
                                <div key={entry.id} className="flex flex-wrap items-center gap-2 border-b pb-2 last:border-0 last:pb-0">
                                    <Badge variant={entry.status === 0 ? 'secondary' : 'destructive'}>{entry.status === 0 ? 'ناجح' : 'فاشل'}</Badge>
                                    <span className="text-muted-foreground">{entry.at}</span>
                                    <span>{entry.description}</span>
                                    {entry.user && <span className="text-muted-foreground">— {entry.user}</span>}
                                    {entry.via && <Badge variant="outline">{entry.via === 'ui' ? 'من الشاشة' : 'بالمفتاح'}</Badge>}
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                )}
            </div>

            <Dialog open={confirming} onOpenChange={setConfirming}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <GitBranch className="size-4" />
                            تأكيد النشر على {environment.env}
                        </DialogTitle>
                        <DialogDescription>سيُنفَّذ ما اخترته الآن على الخادم.</DialogDescription>
                    </DialogHeader>

                    <ul className="flex flex-col gap-1 text-sm">
                        {STEPS.filter((step) => options[step.key]).map((step) => (
                            <li key={step.key}>• {step.title}</li>
                        ))}
                        {options.seed && selectedSeeders.length > 0 && (
                            <li>
                                • الزارعات:{' '}
                                {seeders
                                    .filter((seeder) => selectedSeeders.includes(seeder.name))
                                    .map((seeder) => seeder.label)
                                    .join('، ')}
                            </li>
                        )}
                    </ul>

                    {!options.backup && (
                        <div className="border-destructive/50 bg-destructive/10 text-destructive flex items-start gap-2 rounded-md border p-3 text-xs">
                            <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                            <span>النسخة الاحتياطية موقوفة — لن يكون لك ما ترجع إليه إن أفسدت هجرةٌ البيانات.</span>
                        </div>
                    )}

                    {demoSelected.length > 0 && (
                        <div className="flex flex-col gap-2">
                            <div className="border-destructive/50 bg-destructive/10 text-destructive flex items-start gap-2 rounded-md border p-3 text-xs">
                                <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                                <span>
                                    ستُزرع بياناتٌ وهمية ({demoSelected.map((seeder) => seeder.label).join('، ')}). اكتب «{DANGEROUS_WORD}» للمتابعة.
                                </span>
                            </div>
                            <Input value={typed} onChange={(event) => setTyped(event.target.value)} placeholder={DANGEROUS_WORD} />
                        </div>
                    )}

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setConfirming(false)}>
                            إلغاء
                        </Button>
                        <Button onClick={confirmAndStart} disabled={blockedByWord} variant={demoSelected.length > 0 ? 'destructive' : 'default'}>
                            نفّذ النشر
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </Shell>
    );
}

/**
 * السوبر أدمن يرى الشاشة داخل التطبيق كأي صفحة، وحاملُ المفتاح يراها وحدها:
 * القالب المعتاد يبني قائمةً من مستخدمٍ قد لا يوجد، ولا حاجة به إلى خارطة
 * تطبيقٍ لم يدخله.
 */
function Shell({ standalone, children }: { standalone: boolean; children: ReactNode }) {
    if (standalone) {
        return <div className="bg-muted/40 min-h-svh">{children}</div>;
    }

    return <AppLayout breadcrumbs={breadcrumbs}>{children}</AppLayout>;
}

function Detail({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex flex-col">
            <span className="text-muted-foreground text-xs">{label}</span>
            <span className="truncate font-medium" title={value}>
                {value}
            </span>
        </div>
    );
}
