import { ActivityChanges, ActivitySentence, ActorAvatar, LogBadge, SensitiveBadge } from '@/components/activity-entry';
import { TablePagination } from '@/components/data-table';
import { ActiveFilterChips, type FilterChip } from '@/components/reports/active-filter-chips';
import DateRangeBar from '@/components/reports/date-range-bar';
import { FilterSelect, type FilterOption } from '@/components/reports/filter-fields';
import { FilterModal } from '@/components/reports/filter-modal';
import FilterSearch from '@/components/reports/filter-search';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useReportFilters, type FilterValues } from '@/hooks/use-report-filters';
import AppLayout from '@/layouts/app-layout';
import users from '@/routes/users';
import { type BreadcrumbItem } from '@/types';
import { type ActivityEntry, type ActivityFilters, type ActivitySubject, type PagedActivities } from '@/types/activity';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { useMemo } from 'react';

interface Props {
    subject: ActivitySubject;
    activities: PagedActivities;
    logOptions: FilterOption[];
    filters: ActivityFilters;
    defaultFrom: string;
    defaultTo: string;
}

/** اليوم / أمس / التاريخ — ترويسةُ كل مجموعةٍ في الخطّ الزمنيّ. */
function dayLabel(date: string): string {
    const today = new Date();
    const pad = (n: number) => String(n).padStart(2, '0');
    const iso = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

    if (date === iso(today)) return 'اليوم';

    const yesterday = new Date(today);
    yesterday.setDate(today.getDate() - 1);
    if (date === iso(yesterday)) return 'أمس';

    const [y, m, d] = date.split('-');
    return `${d}/${m}/${y}`;
}

/** صفوف الصفحة مجمّعةً بيومها، على ترتيب الخادم (الأحدث أولاً). */
function groupByDay(entries: ActivityEntry[]): { date: string; entries: ActivityEntry[] }[] {
    const groups: { date: string; entries: ActivityEntry[] }[] = [];

    for (const entry of entries) {
        const last = groups[groups.length - 1];
        if (last && last.date === entry.date) last.entries.push(entry);
        else groups.push({ date: entry.date, entries: [entry] });
    }

    return groups;
}

/**
 * تاسك 115: سجلّ حركة مستخدمٍ واحد — خطٌّ زمنيّ مجمّعٌ بالأيام، الوقتُ على
 * اليسار والعمليةُ إلى جانبه، والفروق (قديم ⇐ جديد) في صندوقٍ تحت الصفّ.
 */
export default function UserActivity({ subject, activities, logOptions, filters, defaultFrom, defaultTo }: Props) {
    const pageUrl = users.activity(subject.id).url;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'المستخدمون', href: users.index().url },
        { title: subject.name, href: users.show(subject.id).url },
        { title: 'سجل النشاط', href: pageUrl },
    ];

    const defaults = useMemo<FilterValues>(() => ({ from: defaultFrom, to: defaultTo, log: 'all', search: '' }), [defaultFrom, defaultTo]);

    const applied: FilterValues = { from: filters.from, to: filters.to, log: filters.log, search: filters.search };
    const f = useReportFilters(pageUrl, applied, defaults);

    const groups = useMemo(() => groupByDay(activities.data), [activities.data]);

    const chips: FilterChip[] = [];
    if (f.isActive('log')) {
        const name = logOptions.find((l) => l.value === applied.log)?.label ?? applied.log;
        chips.push({ key: 'log', label: `القسم: ${name}`, onRemove: () => f.remove('log') });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`سجل نشاط ${subject.name}`} />
            <div className="p-4 md:p-6">
                <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <ActorAvatar name={subject.name} className="size-10 text-base" />
                        <div>
                            <h1 className="flex flex-wrap items-center gap-2 text-xl font-bold md:text-2xl">
                                {subject.name}
                                {!subject.isActive && (
                                    <Badge variant="outline" className="border-destructive/40 text-destructive text-xs font-normal">
                                        معطّل
                                    </Badge>
                                )}
                            </h1>
                            <p className="text-muted-foreground text-sm">
                                {[subject.roleLabel, subject.branchName, subject.username].filter(Boolean).join(' · ')}
                            </p>
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        <FilterModal open={f.open} onOpenChange={f.onOpenChange} onApply={f.apply} onReset={f.reset} activeCount={f.activeCount}>
                            <FilterSelect
                                label="القسم"
                                value={f.draft.log}
                                onChange={(v) => f.setField('log', v)}
                                allLabel="كل الأقسام"
                                options={logOptions}
                            />
                        </FilterModal>
                        <Button variant="outline" size="sm" asChild>
                            <Link href={users.show(subject.id).url}>
                                <ArrowRight className="size-4" />
                                الملف الشخصي
                            </Link>
                        </Button>
                    </div>
                </div>

                <Card className="mb-6 flex flex-wrap items-end justify-between gap-x-6 gap-y-4 border px-4 py-3.5">
                    <FilterSearch filters={f} value={applied.search} placeholder="وصف العملية..." />
                    <DateRangeBar filters={f} from={applied.from} to={applied.to} extended />
                </Card>

                <ActiveFilterChips chips={chips} />

                {groups.length === 0 ? (
                    <Card className="text-muted-foreground p-10 text-center text-sm">لا توجد حركة لهذا المستخدم في الفترة المحدَّدة</Card>
                ) : (
                    <Card className="divide-y p-0">
                        {groups.map((group) => (
                            <div key={group.date}>
                                <p className="bg-muted/40 text-muted-foreground px-4 py-2 text-xs font-semibold">{dayLabel(group.date)}</p>
                                <div className="divide-y">
                                    {group.entries.map((entry) => (
                                        <div key={entry.id} className="flex items-start gap-3 px-4 py-3">
                                            <span className="text-muted-foreground w-14 shrink-0 pt-0.5 text-xs tabular-nums" dir="ltr">
                                                {entry.time}
                                            </span>
                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap items-center justify-between gap-2">
                                                    <span className="flex flex-wrap items-center gap-2">
                                                        <ActivitySentence entry={entry} showCauser={false} />
                                                        <SensitiveBadge sensitive={entry.isSensitive} />
                                                    </span>
                                                    <LogBadge label={entry.logLabel} />
                                                </div>
                                                <ActivityChanges changes={entry.changes} details={entry.details} sensitive={entry.isSensitive} />
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </Card>
                )}

                <TablePagination
                    currentPage={activities.meta.current_page}
                    totalPages={activities.meta.last_page}
                    totalItems={activities.meta.total}
                    from={activities.meta.from ?? 0}
                    to={activities.meta.to ?? 0}
                    onPageChange={(page) => router.reload({ data: { page } })}
                />
            </div>
        </AppLayout>
    );
}
