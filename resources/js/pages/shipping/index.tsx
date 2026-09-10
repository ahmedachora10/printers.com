import { destroy as destroyProvider, toggleStatus as toggleProvider } from '@/actions/App/Http/Controllers/DeliveryProviderController';
import { destroy as destroyZone, toggleStatus as toggleZone } from '@/actions/App/Http/Controllers/DeliveryZoneController';
import { index as shippingIndex } from '@/actions/App/Http/Controllers/ShippingController';
import { index as deliveriesIndex } from '@/actions/App/Http/Controllers/DeliveryLogController';
import { DataTable, type ColumnDef } from '@/components/data-table';
import DeliveryProviderFormModal from '@/components/shipping/delivery-provider-form-modal';
import DeliveryZoneFormModal from '@/components/shipping/delivery-zone-form-modal';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import type { DeliveryProvider, DeliveryZone, EnumOption, ShippingBranchOption } from '@/types/shipping';
import { Link, router } from '@inertiajs/react';
import { ClipboardList, Pencil, Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'التوصيل', href: '/shipping' }];

interface Props {
    providers: DeliveryProvider[];
    zones: DeliveryZone[];
    providerTypes: EnumOption[];
    zoneTypes: EnumOption[];
    canManage: boolean;
    isSuperAdmin: boolean;
    branches: ShippingBranchOption[];
    filters: { branch_id?: string };
}

function StatusBadge({ active, label }: { active: boolean; label: string }) {
    return active ? (
        <Badge variant="outline" className="gap-1.5 border-green-200 bg-green-50 text-green-700">
            <span className="inline-block size-1.5 rounded-full bg-green-500" />
            {label}
        </Badge>
    ) : (
        <Badge variant="outline" className="gap-1.5 border-border bg-muted/60 text-muted-foreground">
            <span className="inline-block size-1.5 rounded-full bg-muted-foreground/50" />
            غير {label}
        </Badge>
    );
}

export default function ShippingIndex({
    providers,
    zones,
    providerTypes,
    zoneTypes,
    canManage,
    isSuperAdmin,
    branches,
    filters,
}: Props) {
    const [providerFormOpen, setProviderFormOpen] = useState(false);
    const [editingProvider, setEditingProvider] = useState<DeliveryProvider | null>(null);
    const [deletingProvider, setDeletingProvider] = useState<DeliveryProvider | null>(null);

    const [zoneFormOpen, setZoneFormOpen] = useState(false);
    const [editingZone, setEditingZone] = useState<DeliveryZone | null>(null);
    const [deletingZone, setDeletingZone] = useState<DeliveryZone | null>(null);

    const branchFilter = filters.branch_id ?? '';
    const selectedBranchId = branchFilter ? Number(branchFilter) : null;

    function handleBranchFilter(value: string) {
        router.get(shippingIndex.url(), value === 'all' ? {} : { branch_id: value }, {
            preserveState: true,
            replace: true,
        });
    }

    const providerColumns = useMemo<ColumnDef<DeliveryProvider>[]>(
        () => [
            {
                key: 'name',
                header: 'الاسم',
                cell: (item) => <span className="font-medium">{item.name}</span>,
            },
            {
                key: 'type',
                header: 'النوع',
                cell: (item) => <Badge variant="secondary">{item.type.label}</Badge>,
            },
            {
                key: 'phone',
                header: 'الجوال',
                cell: (item) =>
                    item.phone ? (
                        <span dir="ltr" className="inline-block text-start">
                            {item.phone}
                        </span>
                    ) : (
                        <span className="text-muted-foreground">—</span>
                    ),
            },
            ...(isSuperAdmin
                ? [
                      {
                          key: 'branch',
                          header: 'الفرع',
                          cell: (item: DeliveryProvider) => item.branchName ?? '—',
                      } as ColumnDef<DeliveryProvider>,
                  ]
                : []),
            {
                key: 'isActive',
                header: 'الحالة',
                cell: (item) =>
                    item.canEdit ? (
                        <button
                            onClick={() => router.patch(toggleProvider.url(item), {}, { preserveScroll: true })}
                            className="cursor-pointer"
                        >
                            <StatusBadge active={item.isActive} label="نشط" />
                        </button>
                    ) : (
                        <StatusBadge active={item.isActive} label="نشط" />
                    ),
            },
            {
                key: 'actions',
                header: '',
                headerClassName: 'w-24',
                cell: (item) =>
                    item.canEdit ? (
                        <div className="flex items-center gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => {
                                    setEditingProvider(item);
                                    setProviderFormOpen(true);
                                }}
                            >
                                <Pencil className="h-3.5 w-3.5" />
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                className="text-destructive hover:text-destructive"
                                onClick={() => setDeletingProvider(item)}
                            >
                                <Trash2 className="h-3.5 w-3.5" />
                            </Button>
                        </div>
                    ) : null,
            },
        ],
        [isSuperAdmin],
    );

    const zoneColumns = useMemo<ColumnDef<DeliveryZone>[]>(
        () => [
            {
                key: 'name',
                header: 'الاسم',
                cell: (item) => <span className="font-medium">{item.name}</span>,
            },
            {
                key: 'type',
                header: 'النوع',
                cell: (item) => <Badge variant="secondary">{item.type.label}</Badge>,
            },
            {
                key: 'range',
                header: 'المدى',
                cell: (item) =>
                    item.rangeLabel ? (
                        <span dir="ltr" className="inline-block text-start">
                            {item.rangeLabel}
                        </span>
                    ) : (
                        <span className="text-muted-foreground">—</span>
                    ),
            },
            {
                key: 'price',
                header: 'السعر',
                cell: (item) => <span className="font-medium">{formatCurrency(item.price)}</span>,
            },
            ...(isSuperAdmin
                ? [
                      {
                          key: 'branch',
                          header: 'الفرع',
                          cell: (item: DeliveryZone) => item.branchName ?? '—',
                      } as ColumnDef<DeliveryZone>,
                  ]
                : []),
            {
                key: 'isActive',
                header: 'الحالة',
                cell: (item) =>
                    item.canEdit ? (
                        <button
                            onClick={() => router.patch(toggleZone.url(item), {}, { preserveScroll: true })}
                            className="cursor-pointer"
                        >
                            <StatusBadge active={item.isActive} label="نشطة" />
                        </button>
                    ) : (
                        <StatusBadge active={item.isActive} label="نشطة" />
                    ),
            },
            {
                key: 'actions',
                header: '',
                headerClassName: 'w-24',
                cell: (item) =>
                    item.canEdit ? (
                        <div className="flex items-center gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => {
                                    setEditingZone(item);
                                    setZoneFormOpen(true);
                                }}
                            >
                                <Pencil className="h-3.5 w-3.5" />
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                className="text-destructive hover:text-destructive"
                                onClick={() => setDeletingZone(item)}
                            >
                                <Trash2 className="h-3.5 w-3.5" />
                            </Button>
                        </div>
                    ) : null,
            },
        ],
        [isSuperAdmin],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="p-6">
                <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold">التوصيل</h1>
                        <p className="text-sm text-muted-foreground">
                            السائقون وشركات التوصيل، وأسعار التوصيل حسب الحي أو المسافة.
                        </p>
                    </div>

                    {/* تاسك 93: كشف المتابعة اليومية — قريباً من إدارة السائقين
                        الذين يظهرون فيه. */}
                    <Button variant="outline" asChild>
                        <Link href={deliveriesIndex.url()}>
                            <ClipboardList className="size-4" /> كشف التوصيل
                        </Link>
                    </Button>

                    {isSuperAdmin && (
                        <Select value={branchFilter || 'all'} onValueChange={handleBranchFilter}>
                            <SelectTrigger className="w-56">
                                <SelectValue placeholder="كل الفروع" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">كل الفروع</SelectItem>
                                {branches.map((branch) => (
                                    <SelectItem key={branch.id} value={String(branch.id)}>
                                        {branch.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )}
                </div>

                <Tabs defaultValue="providers">
                    <TabsList className="mb-4">
                        <TabsTrigger value="providers">السائقون وشركات التوصيل</TabsTrigger>
                        <TabsTrigger value="zones">أسعار التوصيل</TabsTrigger>
                    </TabsList>

                    <TabsContent value="providers">
                        <div className="mb-4 flex justify-end">
                            {canManage && (
                                <Button
                                    size="sm"
                                    onClick={() => {
                                        setEditingProvider(null);
                                        setProviderFormOpen(true);
                                    }}
                                >
                                    <Plus className="size-4" /> إضافة مزوّد
                                </Button>
                            )}
                        </div>

                        <DataTable
                            columns={providerColumns}
                            data={providers}
                            keyExtractor={(item) => item.id}
                            emptyState="لا يوجد سائقون أو شركات توصيل بعد."
                        />
                    </TabsContent>

                    <TabsContent value="zones">
                        <div className="mb-4 flex items-center justify-between gap-3">
                            <p className="text-sm text-muted-foreground">
                                الأسعار شاملة لضريبة القيمة المضافة. اترك «إلى» فارغاً للشريحة المفتوحة.
                            </p>
                            {canManage && (
                                <Button
                                    size="sm"
                                    onClick={() => {
                                        setEditingZone(null);
                                        setZoneFormOpen(true);
                                    }}
                                >
                                    <Plus className="size-4" /> إضافة شريحة
                                </Button>
                            )}
                        </div>

                        <DataTable
                            columns={zoneColumns}
                            data={zones}
                            keyExtractor={(item) => item.id}
                            emptyState="لا توجد شرائح أسعار بعد."
                        />
                    </TabsContent>
                </Tabs>
            </div>

            <Dialog open={!!deletingProvider} onOpenChange={(open) => !open && setDeletingProvider(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>تأكيد الحذف</DialogTitle>
                        <DialogDescription>
                            هل أنت متأكد من حذف "{deletingProvider?.name}"؟ لا يمكن التراجع عن هذا الإجراء.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDeletingProvider(null)}>
                            إلغاء
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() => {
                                if (!deletingProvider) return;
                                router.delete(destroyProvider.url(deletingProvider), {
                                    preserveScroll: true,
                                    onFinish: () => setDeletingProvider(null),
                                });
                            }}
                        >
                            حذف
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={!!deletingZone} onOpenChange={(open) => !open && setDeletingZone(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>تأكيد الحذف</DialogTitle>
                        <DialogDescription>
                            هل أنت متأكد من حذف شريحة "{deletingZone?.name}"؟ لا يمكن التراجع عن هذا الإجراء.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDeletingZone(null)}>
                            إلغاء
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() => {
                                if (!deletingZone) return;
                                router.delete(destroyZone.url(deletingZone), {
                                    preserveScroll: true,
                                    onFinish: () => setDeletingZone(null),
                                });
                            }}
                        >
                            حذف
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <DeliveryProviderFormModal
                key={`provider-${editingProvider?.id ?? 'create'}`}
                open={providerFormOpen}
                onOpenChange={setProviderFormOpen}
                provider={editingProvider ?? undefined}
                types={providerTypes}
                branches={branches}
                isSuperAdmin={isSuperAdmin}
                defaultBranchId={selectedBranchId}
            />

            <DeliveryZoneFormModal
                key={`zone-${editingZone?.id ?? 'create'}`}
                open={zoneFormOpen}
                onOpenChange={setZoneFormOpen}
                zone={editingZone ?? undefined}
                types={zoneTypes}
                branches={branches}
                isSuperAdmin={isSuperAdmin}
                defaultBranchId={selectedBranchId}
            />
        </AppLayout>
    );
}
