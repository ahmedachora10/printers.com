import { cn } from '@/lib/utils';
import { EditorContent, useEditor, useEditorState, type Editor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import { Bold, Heading3, Italic, List, ListOrdered, Quote, Redo2, Strikethrough, Underline as UnderlineIcon, Undo2 } from 'lucide-react';
import { useEffect } from 'react';
import { Toggle } from './toggle';

interface Props {
    value: string;
    onChange: (html: string) => void;
    id?: string;
    placeholder?: string;
    disabled?: boolean;
    className?: string;
}

/**
 * Small rich-text field built on Tiptap. Emits HTML, and an empty document is
 * emitted as an empty string so it validates as nullable on the server.
 */
export default function RichTextEditor({ value, onChange, id, placeholder, disabled = false, className }: Props) {
    const editor = useEditor({
        // The page is server-rendered, so let the editor mount on the client.
        immediatelyRender: false,
        editable: !disabled,
        extensions: [
            StarterKit.configure({
                heading: { levels: [3] },
                codeBlock: false,
                horizontalRule: false,
                link: { openOnClick: false },
            }),
        ],
        content: value || '',
        editorProps: {
            attributes: {
                dir: 'rtl',
                class: 'min-h-32 w-full px-3 py-2 text-sm outline-hidden',
                ...(id ? { id } : {}),
            },
        },
        onUpdate: ({ editor }) => onChange(editor.isEmpty ? '' : editor.getHTML()),
    });

    // Re-seed the document when the field is driven from outside (the modal
    // switching to another user, or a reset). Comparing against the current
    // HTML keeps our own onUpdate emissions from looping back in.
    useEffect(() => {
        if (!editor) return;

        const next = value || '';

        if (next !== (editor.isEmpty ? '' : editor.getHTML())) {
            editor.commands.setContent(next, { emitUpdate: false });
        }
    }, [editor, value]);

    useEffect(() => {
        editor?.setEditable(!disabled);
    }, [editor, disabled]);

    return (
        <div
            className={cn(
                'border-input bg-background overflow-hidden rounded-md border',
                'focus-within:ring-ring focus-within:border-ring focus-within:ring-1',
                disabled && 'pointer-events-none opacity-60',
                className,
            )}
        >
            <Toolbar editor={editor} />

            <div className="relative">
                <Placeholder editor={editor} text={placeholder} />
                <EditorContent editor={editor} className="rich-text-content max-h-64 overflow-y-auto" />
            </div>
        </div>
    );
}

/**
 * The toolbar's pressed states are read through useEditorState: Tiptap v3 does
 * not re-render on every transaction, so subscribing to just the flags we draw
 * is what keeps the buttons in step with the caret.
 */
function Toolbar({ editor }: { editor: Editor | null }) {
    const state = useEditorState({
        editor,
        selector: ({ editor }) => ({
            bold: !!editor?.isActive('bold'),
            italic: !!editor?.isActive('italic'),
            underline: !!editor?.isActive('underline'),
            strike: !!editor?.isActive('strike'),
            heading: !!editor?.isActive('heading', { level: 3 }),
            bulletList: !!editor?.isActive('bulletList'),
            orderedList: !!editor?.isActive('orderedList'),
            blockquote: !!editor?.isActive('blockquote'),
            canUndo: !!editor?.can().undo(),
            canRedo: !!editor?.can().redo(),
        }),
    });

    if (!editor || !state) {
        return <div className="bg-muted/40 border-input h-10 border-b" />;
    }

    return (
        <div className="bg-muted/40 border-input flex flex-wrap items-center gap-0.5 border-b p-1">
            <ToolbarToggle label="عريض" active={state.bold} onClick={() => editor.chain().focus().toggleBold().run()}>
                <Bold />
            </ToolbarToggle>
            <ToolbarToggle label="مائل" active={state.italic} onClick={() => editor.chain().focus().toggleItalic().run()}>
                <Italic />
            </ToolbarToggle>
            <ToolbarToggle label="تحته خط" active={state.underline} onClick={() => editor.chain().focus().toggleUnderline().run()}>
                <UnderlineIcon />
            </ToolbarToggle>
            <ToolbarToggle label="يتوسطه خط" active={state.strike} onClick={() => editor.chain().focus().toggleStrike().run()}>
                <Strikethrough />
            </ToolbarToggle>

            <span className="bg-border mx-1 h-5 w-px" />

            <ToolbarToggle label="عنوان" active={state.heading} onClick={() => editor.chain().focus().toggleHeading({ level: 3 }).run()}>
                <Heading3 />
            </ToolbarToggle>
            <ToolbarToggle label="قائمة نقطية" active={state.bulletList} onClick={() => editor.chain().focus().toggleBulletList().run()}>
                <List />
            </ToolbarToggle>
            <ToolbarToggle label="قائمة مرقّمة" active={state.orderedList} onClick={() => editor.chain().focus().toggleOrderedList().run()}>
                <ListOrdered />
            </ToolbarToggle>
            <ToolbarToggle label="اقتباس" active={state.blockquote} onClick={() => editor.chain().focus().toggleBlockquote().run()}>
                <Quote />
            </ToolbarToggle>

            <span className="bg-border mx-1 h-5 w-px" />

            <ToolbarToggle label="تراجع" active={false} disabled={!state.canUndo} onClick={() => editor.chain().focus().undo().run()}>
                <Undo2 />
            </ToolbarToggle>
            <ToolbarToggle label="إعادة" active={false} disabled={!state.canRedo} onClick={() => editor.chain().focus().redo().run()}>
                <Redo2 />
            </ToolbarToggle>
        </div>
    );
}

function ToolbarToggle({
    label,
    active,
    disabled,
    onClick,
    children,
}: {
    label: string;
    active: boolean;
    disabled?: boolean;
    onClick: () => void;
    children: React.ReactNode;
}) {
    return (
        <Toggle
            size="sm"
            // The editor owns the selection — never let the button steal focus.
            onMouseDown={(e) => e.preventDefault()}
            pressed={active}
            onPressedChange={onClick}
            disabled={disabled}
            title={label}
            aria-label={label}
            className="size-8 min-w-8 px-0"
        >
            {children}
        </Toggle>
    );
}

function Placeholder({ editor, text }: { editor: Editor | null; text?: string }) {
    const isEmpty = useEditorState({
        editor,
        selector: ({ editor }) => !!editor?.isEmpty,
    });

    if (!text || !isEmpty) {
        return null;
    }

    return <span className="text-muted-foreground pointer-events-none absolute top-2 right-3 text-sm">{text}</span>;
}
