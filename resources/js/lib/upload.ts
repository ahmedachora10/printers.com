/**
 * A file POST that reports its upload progress.
 *
 * Inertia's own `router.post` cannot serve the import dialog: it expects a
 * page response and swallows the JSON report we need on screen. XHR (rather
 * than fetch) is what gives us the upload percentage the dialog shows while a
 * large sheet travels.
 */

/** Laravel's session CSRF token, as the cookie the app already sets. */
function csrfToken(): string {
    const cookie = document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN='));

    return cookie ? decodeURIComponent(cookie.split('=').slice(1).join('=')) : '';
}

export class UploadError extends Error {
    constructor(
        message: string,
        /** Per-field messages from a 422, when the server sent any. */
        public readonly errors: Record<string, string[]> = {},
    ) {
        super(message);
    }
}

export async function postFormData<T>(
    url: string,
    data: Record<string, string | number | Blob | null | undefined>,
    onProgress?: (percent: number) => void,
): Promise<T> {
    const body = new FormData();

    for (const [key, value] of Object.entries(data)) {
        if (value !== null && value !== undefined) {
            body.append(key, value instanceof Blob ? value : String(value));
        }
    }

    return new Promise<T>((resolve, reject) => {
        const xhr = new XMLHttpRequest();

        xhr.open('POST', url);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('X-XSRF-TOKEN', csrfToken());

        xhr.upload.onprogress = (event) => {
            if (event.lengthComputable && onProgress) {
                onProgress(Math.round((event.loaded / event.total) * 100));
            }
        };

        xhr.onload = () => {
            let payload: unknown = null;

            try {
                payload = JSON.parse(xhr.responseText);
            } catch {
                payload = null;
            }

            if (xhr.status >= 200 && xhr.status < 300) {
                resolve(payload as T);

                return;
            }

            const body = (payload ?? {}) as { message?: string; errors?: Record<string, string[]> };
            const fallback =
                xhr.status === 403
                    ? 'لا تملك صلاحية الاستيراد.'
                    : xhr.status === 419
                      ? 'انتهت الجلسة، يرجى تحديث الصفحة والمحاولة من جديد.'
                      : 'تعذّر إتمام العملية، حاول مرة أخرى.';

            reject(new UploadError(body.message ?? fallback, body.errors ?? {}));
        };

        xhr.onerror = () => reject(new UploadError('تعذّر الاتصال بالخادم.'));

        xhr.send(body);
    });
}
