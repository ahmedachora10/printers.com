import { cn } from '@/lib/utils';
import { ImageIcon, Upload, X } from 'lucide-react';
import { DragEvent, useRef, useState } from 'react';

interface ImageUploadProps {
    value: File | null;
    onChange: (file: File | null) => void;
    currentUrl?: string;
    accept?: string;
    error?: string;
    className?: string;
}

export function ImageUpload({
    value,
    onChange,
    currentUrl,
    accept = 'image/*',
    error,
    className,
}: ImageUploadProps) {
    const inputRef = useRef<HTMLInputElement>(null);
    const [dragging, setDragging] = useState(false);

    const previewUrl = value ? URL.createObjectURL(value) : (currentUrl ?? null);
    const hasNewFile = !!value;

    function handleDrop(e: DragEvent<HTMLDivElement>) {
        e.preventDefault();
        setDragging(false);
        const file = e.dataTransfer.files[0];
        if (file) onChange(file);
    }

    function handleDragOver(e: DragEvent<HTMLDivElement>) {
        e.preventDefault();
        setDragging(true);
    }

    function handleDragLeave(e: DragEvent<HTMLDivElement>) {
        if (!e.currentTarget.contains(e.relatedTarget as Node)) {
            setDragging(false);
        }
    }

    function handleClick() {
        inputRef.current?.click();
    }

    function handleChange(e: React.ChangeEvent<HTMLInputElement>) {
        onChange(e.target.files?.[0] ?? null);
        e.target.value = '';
    }

    function handleClear(e: React.MouseEvent) {
        e.stopPropagation();
        onChange(null);
        if (inputRef.current) inputRef.current.value = '';
    }

    return (
        <div className={cn('space-y-1', className)}>
            <div
                role="button"
                tabIndex={0}
                onClick={handleClick}
                onKeyDown={(e) => e.key === 'Enter' && handleClick()}
                onDrop={handleDrop}
                onDragOver={handleDragOver}
                onDragLeave={handleDragLeave}
                className={cn(
                    'relative flex min-h-28 cursor-pointer items-center justify-center rounded-lg border-2 border-dashed transition-colors select-none',
                    dragging
                        ? 'border-primary bg-primary/5'
                        : previewUrl
                          ? 'border-border bg-muted/30 hover:border-primary/50'
                          : 'border-border bg-muted/40 hover:border-primary/50 hover:bg-muted/60',
                    error && 'border-destructive/60',
                )}
            >
                {previewUrl ? (
                    <div className="flex w-full items-center gap-4 px-4 py-3">
                        <img
                            src={previewUrl}
                            alt="معاينة"
                            className="h-16 w-16 shrink-0 rounded-md border bg-white object-contain p-1"
                        />

                        <div className="min-w-0 flex-1 text-start">
                            {hasNewFile ? (
                                <>
                                    <p className="truncate text-sm font-medium">{value!.name}</p>
                                    <p className="text-xs text-muted-foreground">{formatBytes(value!.size)}</p>
                                    {currentUrl && (
                                        <p className="mt-1 text-xs text-amber-600">
                                            سيتم استبدال الشعار الحالي
                                        </p>
                                    )}
                                </>
                            ) : (
                                <>
                                    <p className="text-sm font-medium text-foreground">الشعار الحالي</p>
                                    <p className="text-xs text-muted-foreground">اضغط أو اسحب ملفاً لاستبداله</p>
                                </>
                            )}
                        </div>

                        {hasNewFile && (
                            <button
                                type="button"
                                onClick={handleClear}
                                aria-label="إزالة الملف"
                                className="shrink-0 rounded-full p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-destructive"
                            >
                                <X className="h-4 w-4" />
                            </button>
                        )}
                    </div>
                ) : (
                    <div className="flex flex-col items-center gap-2 px-4 py-5 text-center">
                        <div
                            className={cn(
                                'rounded-full p-3 transition-colors',
                                dragging ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground',
                            )}
                        >
                            {dragging ? (
                                <ImageIcon className="h-5 w-5" />
                            ) : (
                                <Upload className="h-5 w-5" />
                            )}
                        </div>
                        <div>
                            <p className="text-sm font-medium">
                                {dragging ? 'أفلت الملف هنا' : 'اسحب وأفلت أو اضغط للاختيار'}
                            </p>
                            <p className="mt-0.5 text-xs text-muted-foreground">PNG, JPG, WEBP, GIF</p>
                        </div>
                    </div>
                )}
            </div>

            <input ref={inputRef} type="file" accept={accept} className="sr-only" onChange={handleChange} />

            {error && <p className="text-sm text-destructive">{error}</p>}
        </div>
    );
}

function formatBytes(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1_048_576) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / 1_048_576).toFixed(1)} MB`;
}
