import { Button } from '@/components/ui/button';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { ShieldX } from 'lucide-react';

export default function Forbidden() {
    // صفحة الخطأ قد تُعرض من خارج وسائط الويب (استثناءٌ يسبقها)،
    // وحينها لا مشاركات أصلاً — فلا تُقرأ auth إلا بحذر.
    const auth = usePage<Partial<SharedData>>().props.auth;

    return (
        <>
            <Head title="ليس لديك صلاحية" />
            <div className="bg-background flex min-h-svh flex-col items-center justify-center gap-6 p-6 text-center md:p-10">
                <div className="flex h-16 w-16 items-center justify-center rounded-full bg-destructive/10">
                    <ShieldX className="size-8 text-destructive" />
                </div>
                <div className="space-y-2">
                    <p className="text-4xl font-bold">403</p>
                    <h1 className="text-xl font-medium">ليس لديك الصلاحية للوصول إلى هذه الصفحة</h1>
                    <p className="text-muted-foreground text-sm">
                        يرجى التواصل مع مدير الفرع إذا كنت تعتقد أن هذا خطأ.
                    </p>
                </div>
                <Button asChild>
                    <Link href={route(auth?.user ? 'dashboard' : 'home')}>
                        {auth?.user ? 'العودة إلى لوحة التحكم' : 'العودة إلى الصفحة الرئيسية'}
                    </Link>
                </Button>
            </div>
        </>
    );
}
