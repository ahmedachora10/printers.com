import { ActivityChanges, ActivitySentence, ActorAvatar, LogBadge, SensitiveBadge } from '@/components/activity-entry';
import { DataTable, TablePagination, type ColumnDef } from '@/components/data-table';
import { ActiveFilterChips, type FilterChip } from '@/components/reports/active-filter-chips';
import DateRangeBar from '@/components/reports/date-range-bar';
import { FilterSelect, type FilterOption } from '@/components/reports/filter-fields';
import { FilterModal } from '@/components/reports/filter-modal';
import FilterSearch from '@/components/reports/filter-search';
import { Card } from '@/components/ui/card';
import { useReportFilters, type FilterValues } from '@/hooks/use-report-filters';
import AppLayout from '@/layouts/app-layout';
import activityLog from '@/routes/activity-log';
import users from '@/routes/users';
import { type BreadcrumbItem } from '@/types';
import { type ActivityEntry, type ActivityFilters, type PagedActivities } from '@/types/activity';
import { Head, Link, router } from '@inertiajs/react';
import { useMemo } from 'react';

const PAGE_URL = '/activity-log';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'سجل النشاط', href: activityLog.index().url }];

interface Props {
    activities: PagedActivities;
    users: FilterOption[];
    logOptions: FilterOption[];
    filters: ActivityFilters;
    defaultFrom: string;
    defaultTo: string;
}

/**
 * تاسك 115: كل ما جرى في النظام — من فعله ومتى وعلى أي سجلّ. جدولٌ لأنه كثيف:
 * كل المستخدمين معاً. وسجلّ مستخدمٍ واحدٍ خطٌّ زمنيّ في users/{user}/activity.
 */
export default function ActivityLogIndex({ activities, users: userOptions, logOptions, filters, defaultFrom, defaultTo }: Props) {
    const defaults = useMemo<FilterValues>(
        () => ({ from: defaultFrom, to: defaultTo, log: 'all', user: 'all', search: '' }),
        [defaultFrom, defaultTo],
    );

    const applied: FilterValues = {
        from: filters.from,
        to: filters.to,
        log: filters.log,
        user: filters.user,
        search: filters.search,
    };

    const f = useReportFilters(PAGE_URL, applied, defaults);

    const columns = useMemo<ColumnDef<ActivityEntry>[]>(
        () => [
            {
                key: 'at',
                header: 'التاريخ والوقت',
                className: 'whitespace-nowrap text-muted-foreground text-xs',
                cell: (row) => row.at,
            },
            {
                key: 'causerName',
                header: 'الفاعل',
                cell: (row) => (
                    <span className="flex items-center gap-2">
                        <ActorAvatar name={row.causerName} />
                        {row.causerId ? (
                            <Link href={users.activity(row.causerId).url} className="font-medium hover:underline">
                                {row.causerName}
                            </Link>
                        ) : (
                            <span className="font-medium">{row.causerName}</span>
                        )}
                    </span>
                ),
            },
            {
                key: 'action',
                header: 'العملية',
                cell: (row) => (
                    <div className="min-w-0">
                        <span className="flex flex-wrap items-center gap-2">
                            <ActivitySentence entry={row} showCauser={false} />
                            <SensitiveBadge sensitive={row.isSensitive} />
                        </span>
                        <ActivityChanges changes={row.changes} details={row.details} sensitive={row.isSensitive} max={3} />
                    </div>
                ),
            },
            {
                key: 'logLabel',
                header: 'القسم',
                cell: (row) => <LogBadge label={row.logLabel} />,
            },
        ],
        [],
    );

    const chips: FilterChip[] = [];
    if (f.isActive('user')) {
        const name = userOptions.find((u) => u.value === applied.user)?.label ?? applied.user;
        chips.push({ key: 'user', label: `المستخدم: ${name}`, onRemove: () => f.remove('user') });
    }
    if (f.isActive('log')) {
        const name = logOptions.find((l) => l.value === applied.log)?.label ?? applied.log;
        chips.push({ key: 'log', label: `القسم: ${name}`, onRemove: () => f.remove('log') });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="سجل النشاط" />
            <div className="p-4 md:p-6">
                <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-bold md:text-2xl">سجل النشاط</h1>
                        <p className="text-muted-foreground text-sm">حركة العمليات على النظام — من فعل ماذا ومتى</p>
                    </div>
                    <FilterModal open={f.open} onOpenChange={f.onOpenChange} onApply={f.apply} onReset={f.reset} activeCount={f.activeCount}>
                        <FilterSelect
                            label="المستخدم"
                            value={f.draft.user}
                            onChange={(v) => f.setField('user', v)}
                            allLabel="كل المستخدمين"
                            searchable
                            options={userOptions}
                        />
                        <FilterSelect
                            label="القسم"
                            value={f.draft.log}
                            onChange={(v) => f.setField('log', v)}
                            allLabel="كل الأقسام"
                            options={logOptions}
                        />
                    </FilterModal>
                </div>

                <Card className="mb-6 flex flex-wrap items-end justify-between gap-x-6 gap-y-4 border px-4 py-3.5">
                    <FilterSearch filters={f} value={applied.search} placeholder="اسم المستخدم أو وصف العملية..." />
                    <DateRangeBar filters={f} from={applied.from} to={applied.to} extended />
                </Card>

                <ActiveFilterChips chips={chips} />

                <DataTable
                    rowOffset={Number(activities.meta.from ?? 1) - 1}
                    columns={columns}
                    data={activities.data}
                    keyExtractor={(row) => row.id}
                    emptyState={<span className="text-muted-foreground">لا توجد حركة في هذه الفترة</span>}
                />

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
