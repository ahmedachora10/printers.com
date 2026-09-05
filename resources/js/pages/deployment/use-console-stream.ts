import { useEffect, useRef, useState } from 'react';

function readCookie(name: string): string {
    const match = document.cookie.split('; ').find((row) => row.startsWith(name + '='));

    return match ? decodeURIComponent(match.split('=').slice(1).join('=')) : '';
}

/**
 * قراءة مخرجات أمرٍ طويل من الخادم سطراً سطراً.
 *
 * يشترك فيه بابان — النشر الكامل والأوامر المفردة — وفيه تفصيلتان لا تُنسيان:
 * ترويسة Accept تبدأ بـ application/json وإلا عاد خطأ التحقّق صفحةً كاملة،
 * وما ليس نوعه text/plain ليس مخرجات فلا يُدفع إلى الصندوق (إعادةُ توجيهٍ
 * يتبعها fetch تعود 200 وتبدو نجاحاً).
 */
export function useConsoleStream() {
    const [output, setOutput] = useState('');
    const [running, setRunning] = useState(false);
    const [result, setResult] = useState<'success' | 'failure' | null>(null);
    const [errors, setErrors] = useState<string[]>([]);

    const consoleRef = useRef<HTMLPreElement>(null);

    // المخرجات تُلاحَق إلى آخرها ما دامت تُكتب.
    useEffect(() => {
        consoleRef.current?.scrollTo({ top: consoleRef.current.scrollHeight });
    }, [output]);

    // إغلاق التبويب أثناء التنفيذ لا يُوقفه على الخادم، لكنه يُعمي صاحبه عنه.
    useEffect(() => {
        if (!running) {
            return;
        }

        const warn = (event: BeforeUnloadEvent) => event.preventDefault();
        window.addEventListener('beforeunload', warn);

        return () => window.removeEventListener('beforeunload', warn);
    }, [running]);

    const start = async (url: string, payload: unknown) => {
        setRunning(true);
        setResult(null);
        setErrors([]);
        setOutput('');

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json, text/plain',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': readCookie('XSRF-TOKEN'),
                },
                body: JSON.stringify(payload),
            });

            const streaming = response.headers.get('content-type')?.includes('text/plain') ?? false;

            if (!response.ok || !response.body || !streaming) {
                const body = await response.json().catch(() => null);

                setErrors(
                    body?.errors
                        ? Object.values(body.errors as Record<string, string[] | string>)
                              .flat()
                              .map(String)
                        : [body?.message ?? 'تعذّر بدء التنفيذ (' + response.status + ').'],
                );
                setResult('failure');

                return;
            }

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let text = '';

            for (;;) {
                const { done, value } = await reader.read();

                if (done) {
                    break;
                }

                text += decoder.decode(value, { stream: true });
                setOutput(text);
            }

            // ترويسة الردّ سبقت التنفيذ كلَّه، فالحكم من السطر الختامي وحده.
            setResult(text.includes('== اكتمل النشر ==') ? 'success' : 'failure');
        } catch (error) {
            setErrors([error instanceof Error ? error.message : 'انقطع الاتصال بالخادم.']);
            setResult('failure');
        } finally {
            setRunning(false);
        }
    };

    return { output, running, result, errors, consoleRef, start };
}
