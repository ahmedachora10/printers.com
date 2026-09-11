<?php

namespace App\Actions\InvoiceMessage;

use App\Models\InvoiceMessage;
use App\Models\ServiceInvoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * تاسك 100 — حمولة المحادثة الداخلية على شاشة الفاتورة. قراءتها **تقدّم موضع
 * قراءة الناظر**: الشاشة تستطلعها كل 30 ثانية وهي ظاهرة، فما عُرض قد قُرئ.
 *
 * الحالة المعروضة لكل رسالة: «تم الإجراء» ⟵ «مقروءة» (قرأها غير مرسلها) ⟵
 * «جديدة». و`isNew` شيءٌ آخر: جديدةٌ على **هذا** الناظر منذ آخر زيارة.
 */
class BuildInvoiceThreadAction
{
    /** @return array<string, mixed> */
    public function handle(ServiceInvoice $invoice, User $viewer): array
    {
        $lastRead = (int) DB::table('invoice_thread_participants')
            ->where('user_id', $viewer->id)
            ->where('service_invoice_id', $invoice->id)
            ->value('last_read_message_id');

        // المحذوفة تبقى في الخيط «حُذفت بواسطة…» بلا نصّها.
        $messages = $invoice->messages()
            ->withTrashed()
            ->with(['author:id,name', 'author.roles:id,name', 'actionedBy:id,name', 'media'])
            ->orderBy('id')
            ->get();

        InvoiceMessage::markRead($viewer, $invoice, (int) $messages->max('id'));

        $pointers = DB::table('invoice_thread_participants')
            ->where('service_invoice_id', $invoice->id)
            ->pluck('last_read_message_id', 'user_id');

        $deletedBy = Activity::query()
            ->where('subject_type', (new InvoiceMessage)->getMorphClass())
            ->whereIn('subject_id', $messages->filter->trashed()->pluck('id'))
            ->where('description', InvoiceMessage::LOG_DELETED)
            ->with('causer:id,name')
            ->get()
            ->mapWithKeys(fn (Activity $a) => [$a->subject_id => $a->causer?->name]);

        $gate = Gate::forUser($viewer);
        $canPost = $gate->allows('postMessage', $invoice);
        $canModerate = $gate->allows('moderateMessages', $invoice);

        return [
            'messages' => $messages->map(fn (InvoiceMessage $m) => [
                'id' => $m->id,
                'authorId' => $m->user_id,
                'authorName' => $m->user_id === null ? 'ملاحظة سابقة' : ($m->author?->name ?? '—'),
                'authorRole' => $m->author?->roleName?->label(),
                'body' => $m->trashed() ? null : $m->body,
                'createdAt' => $m->created_at?->toIso8601String(),
                'isNew' => $m->user_id !== null && $m->user_id !== $viewer->id && $m->id > $lastRead,
                'isRead' => $pointers->except([$m->user_id])->contains(fn ($pointer) => (int) $pointer >= $m->id),
                'actionedAt' => $m->actioned_at?->toIso8601String(),
                'actionedByName' => $m->actionedBy?->name,
                'editedAt' => $m->edited_at?->toIso8601String(),
                'deletedAt' => $m->deleted_at?->toIso8601String(),
                'deletedByName' => $deletedBy->get($m->id),
                'attachments' => $m->trashed() ? [] : $m->getMedia(InvoiceMessage::ATTACHMENTS)->map(fn (Media $media) => [
                    'id' => $media->id,
                    'name' => $media->file_name,
                    'url' => route('invoices.messages.attachment', ['message' => $m->id, 'media' => $media->id]),
                    'isImage' => str_starts_with((string) $media->mime_type, 'image/'),
                ])->values(),
                'canMarkActioned' => ! $m->trashed() && $m->user_id !== $viewer->id,
            ])->values(),
            'closedAt' => $invoice->messages_closed_at?->toIso8601String(),
            'closedByName' => $invoice->messagesClosedBy?->name,
            'canPost' => $canPost,
            'canModerate' => $canModerate,
            'mentionables' => $canPost
                ? InvoiceMessage::mentionables($invoice)->reject(fn ($m) => $m['token'] === "user:{$viewer->id}")->values()
                : [],
        ];
    }
}
