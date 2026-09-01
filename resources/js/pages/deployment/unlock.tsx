import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Head, useForm } from '@inertiajs/react';
import { KeyRound, Loader2, ServerIcon } from 'lucide-react';
import { type FormEvent } from 'react';

interface Props {
    appName: string;
    /** لا مفتاحَ مضبوطٌ على الخادم — فلا سبيل إلى الفتح، ويُقال ذلك بدل تركه يُجرِّب. */
    configured: boolean;
}

export default function DeploymentUnlock({ appName, configured }: Props) {
    const form = useForm({ token: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/deployment/unlock', { onFinish: () => form.reset('token') });
    };

    return (
        <div className="bg-muted/40 flex min-h-svh items-center justify-center p-4">
            <Head title="النشر" />

            <Card className="w-full max-w-sm">
                <CardHeader className="items-center gap-1 text-center">
                    <ServerIcon className="text-muted-foreground size-8" />
                    <CardTitle>نشر {appName}</CardTitle>
                    <p className="text-muted-foreground text-sm">هذه الشاشة لحاملي مفتاح النشر وللمدير العام.</p>
                </CardHeader>

                <CardContent>
                    {configured ? (
                        <form onSubmit={submit} className="flex flex-col gap-4">
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="token">مفتاح النشر</Label>
                                <Input
                                    id="token"
                                    type="password"
                                    value={form.data.token}
                                    onChange={(event) => form.setData('token', event.target.value)}
                                    autoComplete="off"
                                    autoFocus
                                    dir="ltr"
                                    placeholder="DEPLOY_TOKEN"
                                />
                                {form.errors.token && <span className="text-destructive text-xs">{form.errors.token}</span>}
                            </div>

                            <Button type="submit" disabled={form.processing} className="w-full">
                                {form.processing ? <Loader2 className="size-4 animate-spin" /> : <KeyRound className="size-4" />}
                                افتح
                            </Button>
                        </form>
                    ) : (
                        <p className="text-muted-foreground text-sm">
                            لا مفتاح نشرٍ مضبوطٌ على هذا الخادم. اضبط <code dir="ltr">DEPLOY_TOKEN</code> في ملف البيئة، أو ادخل بحساب المدير العام.
                        </p>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}
