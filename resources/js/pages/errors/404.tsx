import { Button } from '@/components/ui/button';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { FileQuestion } from 'lucide-react';

export default function NotFound() {
    // صفحة الخطأ قد تُعرض من خارج وسائط الويب (استثناءٌ يسبقها)،
    // وحينها لا مشاركات أصلاً — فلا تُقرأ auth إلا بحذر.
    const auth = usePage<Partial<SharedData>>().props.auth;

    return (
        <>
            <Head title="الصفحة غير موجودة" />
            <div className="bg-background flex min-h-svh flex-col items-center justify-center gap-6 p-6 text-center md:p-10">
                <div className="bg-muted flex h-16 w-16 items-center justify-center rounded-full">
                    <FileQuestion className="text-muted-foreground size-8" />
                </div>
                <div className="space-y-2">
                    <p className="text-4xl font-bold">404</p>
                    <h1 className="text-xl font-medium">الصفحة غير موجودة</h1>
                    <p className="text-muted-foreground text-sm">
                        الصفحة التي تبحث عنها غير موجودة أو تم نقلها.
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
