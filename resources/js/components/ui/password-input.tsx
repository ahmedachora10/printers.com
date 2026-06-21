import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { Eye, EyeOff, RefreshCw } from 'lucide-react';
import * as React from 'react';
import { useState } from 'react';

/**
 * Generate a random password that satisfies the production `Password::defaults()`
 * rules (min 12, mixed case, letters, numbers, symbols). Guarantees at least one
 * character from each required class. Ambiguous characters (0/O, 1/l/I) are omitted.
 */
export function generatePassword(length = 16): string {
    const lower = 'abcdefghijkmnopqrstuvwxyz';
    const upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    const digits = '23456789';
    const symbols = '!@#$%^&*()-_=+[]?';
    const all = lower + upper + digits + symbols;

    const randomInt = (max: number) => {
        const buf = new Uint32Array(1);
        crypto.getRandomValues(buf);
        return buf[0] % max;
    };
    const pick = (set: string) => set[randomInt(set.length)];

    // One guaranteed character per required class, then fill the rest.
    const chars = [pick(lower), pick(upper), pick(digits), pick(symbols)];
    for (let i = chars.length; i < length; i++) {
        chars.push(pick(all));
    }

    // Fisher–Yates shuffle so the guaranteed chars aren't always at the front.
    for (let i = chars.length - 1; i > 0; i--) {
        const j = randomInt(i + 1);
        [chars[i], chars[j]] = [chars[j], chars[i]];
    }

    return chars.join('');
}

export interface PasswordInputProps extends Omit<React.ComponentProps<'input'>, 'type'> {
    /** Render a "generate" button that produces a strong random password. */
    withGenerator?: boolean;
    /**
     * Called with the freshly generated password when the generate button is clicked.
     * Useful for also filling a confirmation field. Falls back to `onChange` when omitted.
     */
    onGenerate?: (password: string) => void;
}

/**
 * Password field with a show/hide toggle and an optional strong-password generator.
 */
export const PasswordInput = React.forwardRef<HTMLInputElement, PasswordInputProps>(
    ({ className, withGenerator = false, onGenerate, onChange, ...props }, ref) => {
        const [visible, setVisible] = useState(false);

        function handleGenerate() {
            const password = generatePassword();
            setVisible(true);
            if (onGenerate) {
                onGenerate(password);
            } else {
                onChange?.({
                    target: { value: password },
                } as React.ChangeEvent<HTMLInputElement>);
            }
        }

        return (
            <div className="flex gap-2">
                <div className="relative flex-1">
                    <Input
                        ref={ref}
                        type={visible ? 'text' : 'password'}
                        className={cn('pr-9', className)}
                        onChange={onChange}
                        {...props}
                    />
                    <button
                        type="button"
                        onClick={() => setVisible((v) => !v)}
                        tabIndex={-1}
                        aria-label={visible ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور'}
                        className="absolute inset-y-0 right-1 flex items-center px-2 text-muted-foreground hover:text-foreground"
                    >
                        {visible ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                    </button>
                </div>

                {withGenerator && (
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        onClick={handleGenerate}
                        title="توليد كلمة مرور قوية"
                        aria-label="توليد كلمة مرور قوية"
                    >
                        <RefreshCw className="size-4" />
                    </Button>
                )}
            </div>
        );
    },
);
PasswordInput.displayName = 'PasswordInput';
