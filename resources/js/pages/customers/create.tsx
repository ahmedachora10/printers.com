import CustomerFormModal from '@/components/customers/customer-form-modal';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { router } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'العملاء', href: '/customers' },
    { title: 'عميل جديد', href: '/customers/create' },
];

export default function CustomerCreate() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <CustomerFormModal
                open={true}
                onOpenChange={(open) => { if (!open) router.visit('/customers'); }}
            />
        </AppLayout>
    );
}
