import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import UserServiceCommissionsCard from '@/components/users/user-service-commissions-card';
import serviceCommissions from '@/routes/users/service-commissions';
import { type ManagedUser, type UserServiceCommission } from '@/types/user';
import { useEffect, useState } from 'react';

interface Props {
    user: ManagedUser | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    canEdit: boolean;
}

// Reuses UserServiceCommissionsCard inside a dialog. The per-service rates are
// not part of the users list payload, so they are lazily fetched when the modal
// opens for a given user.
export default function UserServiceCommissionsModal({ user, open, onOpenChange, canEdit }: Props) {
    const [services, setServices] = useState<UserServiceCommission[] | null>(null);

    useEffect(() => {
        if (!open || !user) return;

        let cancelled = false;
        setServices(null);

        fetch(serviceCommissions.show(user.id).url, {
            headers: { Accept: 'application/json' },
        })
            .then((res) => res.json())
            .then((data) => {
                if (!cancelled) setServices(data.serviceCommissions ?? []);
            })
            .catch(() => {
                if (!cancelled) setServices([]);
            });

        return () => {
            cancelled = true;
        };
    }, [open, user]);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{user?.name ?? 'عمولات الخدمات'}</DialogTitle>
                    <DialogDescription>تحديد نسبة العمولة الخاصة بالموظف لكل خدمة في فرعه.</DialogDescription>
                </DialogHeader>

                {user && services !== null ? (
                    <UserServiceCommissionsCard key={user.id} userId={user.id} canEdit={canEdit} services={services} onSaved={() => onOpenChange(false)} />
                ) : (
                    <p className="text-muted-foreground py-8 text-center text-sm">جاري التحميل...</p>
                )}
            </DialogContent>
        </Dialog>
    );
}
