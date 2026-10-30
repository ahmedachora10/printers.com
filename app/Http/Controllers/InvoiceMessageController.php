<?php

namespace App\Http\Controllers;

use App\Actions\InvoiceMessage\PostInvoiceMessageAction;
use App\Http\Controllers\Concerns\BuildsPagedProps;
use App\Http\Requests\InvoiceMessage\StoreInvoiceMessageRequest;
use App\Models\InvoiceMessage;
use App\Models\ServiceInvoice;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\Response;

/**
 * تاسك 100 — المحادثة الداخلية على فاتورة الخدمات. الحمولة نفسها تُبنى في
 * InvoiceController::show (BuildInvoiceThreadAction)، وهنا الكتابة وحدها.
 */
class InvoiceMessageController extends Controller
{
    use BuildsPagedProps;

    /**
     * تاسك 209 — قائمة الملاحظات وحدها، الأحدث أولاً، كلٌّ إلى فاتورتها. تُرى بقاعدة
     * المحادثة نفسها: فرع الناظر، والمراجعون كل الخيوط، والموظف خيوط فواتيره
     * وما شارك فيه — والخاصة لمرسلها ومستلمها وحدهما.
     */
    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();
        $reviewer = Gate::allows('review', ServiceInvoice::class);
        abort_unless($reviewer || $user->roleName?->isEmployee(), 403);

        $messages = InvoiceMessage::query()
            ->with(['author:id,name', 'invoice:id,invoice_number'])
            ->where(InvoiceMessage::visibleTo($user))
            ->whereHas('invoice', fn ($q) => $q
                ->when(! $user->roleName->isSuperAdmin(), fn ($q) => $q->where('branch_id', $user->branchId))
                ->when(! $reviewer, fn ($q) => $q->where(fn ($q) => $q
                    ->where('user_id', $user->id)
                    ->orWhereExists(fn ($p) => $p->from('invoice_thread_participants')
                        ->whereColumn('invoice_thread_participants.service_invoice_id', 'service_invoices.id')
                        ->where('invoice_thread_participants.user_id', $user->id)))))
            ->latest('id')
            ->paginate(30);

        $names = User::whereIn('id', collect($messages->items())
            ->pluck('private_to')
            ->filter(fn ($token) => str_starts_with((string) $token, 'user:'))
            ->map(fn ($token) => (int) substr($token, 5)))
            ->pluck('name', 'id');
        $privateLabel = fn (?string $token) => $token === null ? null
            : (InvoiceMessage::ROLE_LABELS[$token] ?? $names[(int) substr($token, 5)] ?? '—');

        return Inertia::render('invoices/messages', [
            'messages' => $this->pagedProp($messages, fn (InvoiceMessage $m) => [
                'id' => $m->id,
                'invoiceId' => $m->service_invoice_id,
                'invoiceNumber' => $m->invoice?->invoice_number,
                'authorName' => $m->user_id === null ? 'ملاحظة سابقة' : ($m->author?->name ?? '—'),
                'body' => $m->body,
                'privateTo' => $privateLabel($m->private_to),
                'createdAt' => $m->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function store(StoreInvoiceMessageRequest $request, ServiceInvoice $invoice, PostInvoiceMessageAction $action): RedirectResponse
    {
        Gate::authorize('postMessage', $invoice);

        $action->handle(
            $invoice,
            $request->user(),
            $request->validated('body'),
            $request->validated('mentions', []),
            $request->file('attachments', []),
            $request->validated('private_to'),
        );

        return back();
    }

    /** التعديل لمدير الفرع والسوبر أدمن، والنص القديم يُحفظ في السجلّ. */
    public function update(Request $request, InvoiceMessage $message): RedirectResponse
    {
        Gate::authorize('moderateMessages', $message->invoice);
        abort_unless($message->isVisibleTo($request->user()), 404);

        $body = trim($request->validate(
            ['body' => ['required', 'string', 'max:2000']],
            ['body.required' => 'نص الرسالة مطلوب.', 'body.max' => 'الرسالة يجب ألا تتجاوز 2000 حرف.'],
        )['body']);

        DB::transaction(function () use ($request, $message, $body) {
            activity('invoices')
                ->causedBy($request->user())
                ->performedOn($message)
                ->withProperties(['old' => $message->body, 'new' => $body])
                ->log(InvoiceMessage::LOG_EDITED);

            $message->update(['body' => $body, 'edited_at' => now()]);
        });

        return back();
    }

    /** حذفٌ ناعم: الرسالة تبقى في الخيط «حُذفت بواسطة…»، ونصّها في السجلّ. */
    public function destroy(Request $request, InvoiceMessage $message): RedirectResponse
    {
        Gate::authorize('moderateMessages', $message->invoice);
        abort_unless($message->isVisibleTo($request->user()), 404);

        DB::transaction(function () use ($request, $message) {
            activity('invoices')
                ->causedBy($request->user())
                ->performedOn($message)
                ->withProperties(['old' => $message->body])
                ->log(InvoiceMessage::LOG_DELETED);

            $message->delete();
        });

        return back();
    }

    public function toggleActioned(Request $request, InvoiceMessage $message): RedirectResponse
    {
        Gate::authorize('actionMessage', [$message->invoice, $message]);
        abort_unless($message->isVisibleTo($request->user()), 404);

        $message->update($message->actioned_at === null
            ? ['actioned_at' => now(), 'actioned_by' => $request->user()->id]
            : ['actioned_at' => null, 'actioned_by' => null]);

        return back();
    }

    /** إغلاق المحادثة وفتحها — سجلّ الفاتورة (LogsActivity) يحفظ من ومتى. */
    public function toggleClosed(Request $request, ServiceInvoice $invoice): RedirectResponse
    {
        Gate::authorize('moderateMessages', $invoice);

        $invoice->update($invoice->messages_closed_at === null
            ? ['messages_closed_at' => now(), 'messages_closed_by' => $request->user()->id]
            : ['messages_closed_at' => null, 'messages_closed_by' => null]);

        return back();
    }

    /** المرفق على القرص الخاص، يُبثّ لمن يرى الخيط وحده. */
    public function attachment(Request $request, InvoiceMessage $message, Media $media): Response
    {
        Gate::authorize('viewMessages', $message->invoice);
        abort_unless($message->isVisibleTo($request->user()), 404);

        abort_unless($media->model_type === $message->getMorphClass() && (int) $media->model_id === $message->id, 404);

        return $media->toInlineResponse($request);
    }
}
