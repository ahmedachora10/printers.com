import CustomerFormModal from '@/components/customers/customer-form-modal';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type Customer } from '@/types/customer';
import { router } from '@inertiajs/react';

interface Props {
    customer: Customer;
}

export default function CustomerEdit({ customer }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'العملاء', href: '/customers' },
        { title: customer.fullName, href: `/customers/${customer.id}` },
        { title: 'تعديل', href: `/customers/${customer.id}/edit` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <CustomerFormModal
                open={true}
                onOpenChange={(open) => { if (!open) router.visit(`/customers/${customer.id}`); }}
                customer={customer}
            />
        </AppLayout>
    );
}
