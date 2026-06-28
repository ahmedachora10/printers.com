import { store, update } from '@/actions/App/Http/Controllers/ServiceTemplateController';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type ServiceTemplate, type ServiceTemplateFormData } from '@/types/service-template';
import { router, useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import InputError from '../input-error';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    template?: ServiceTemplate;
}

export default function ServiceTemplateFormModal({ open, onOpenChange, template }: Props) {
    const isEdit = !!template;

    const { data, setData, post, processing, errors, reset, clearErrors } = useForm<ServiceTemplateFormData>({
        name: template?.name ?? '',
        description: template?.description ?? '',
        is_active: template?.isActive ?? true,
    });

    useEffect(() => {
        if (template) {
            setData({
                name: template.name ?? '',
                description: template.description ?? '',
                is_active: template.isActive ?? true,
            });
        } else {
            reset();
        }
    }, [template, open]);

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: () => {
                clearErrors();
                onOpenChange(false);
                reset();
            },
        };

        if (isEdit) {
            router.put(update.url(template), data, {
                ...options,
                onError: (errs) =>
                    Object.entries(errs).forEach(([k, v]) =>
                        setData(k as keyof ServiceTemplateFormData, v as never)
                    ),
            });
        } else {
            post(store.url(), options);
        }
    }

    function handleOpenChange(nextOpen: boolean) {
        if (!nextOpen) {
            reset();
            clearErrors();
        }
        onOpenChange(nextOpen);
    }

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{isEdit ? 'تعديل قالب الخدمة' : 'إضافة قالب خدمة'}</DialogTitle>
                </DialogHeader>

                <form id="st-form" onSubmit={handleSubmit} className="space-y-4 py-2">
                    <div className="space-y-1">
                        <Label htmlFor="st-name">
                            اسم الخدمة <span className="text-destructive">*</span>
                        </Label>
                        <Input
                            id="st-name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="أدخل اسم قالب الخدمة"
                            autoFocus
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="st-description">الوصف</Label>
                        <textarea
                            id="st-description"
                            rows={3}
                            className="flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 resize-none"
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                            placeholder="وصف اختياري للخدمة"
                        />
                        <InputError message={errors.description} />
                    </div>

                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="st-active"
                            checked={data.is_active}
                            onCheckedChange={(checked) => setData('is_active', checked === true)}
                        />
                        <Label htmlFor="st-active" className="cursor-pointer">
                            نشط
                        </Label>
                    </div>
                </form>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                        disabled={processing}
                    >
                        إلغاء
                    </Button>
                    <Button type="submit" form="st-form" disabled={processing}>
                        {processing ? 'جاري الحفظ...' : isEdit ? 'حفظ التعديلات' : 'إضافة'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
