<?php

namespace App\Http\Controllers;

use App\Actions\InvoiceMessage\PostInvoiceMessageAction;
use App\Http\Requests\InvoiceMessage\StoreInvoiceMessageRequest;
use App\Models\InvoiceMessage;
use App\Models\ServiceInvoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\Response;

/**
 * تاسك 100 — المحادثة الداخلية على فاتورة الخدمات. الحمولة نفسها تُبنى في
 * InvoiceController::show (BuildInvoiceThreadAction)، وهنا الكتابة وحدها.
 */
class InvoiceMessageController extends Controller
{
    public function store(StoreInvoiceMessageRequest $request, ServiceInvoice $invoice, PostInvoiceMessageAction $action): RedirectResponse
    {
        Gate::authorize('postMessage', $invoice);

        $action->handle(
            $invoice,
            $request->user(),
            $request->validated('body'),
            $request->validated('mentions', []),
            $request->file('attachments', []),
        );

        return back();
    }

    /** التعديل لمدير الفرع والسوبر أدمن، والنص القديم يُحفظ في السجلّ. */
    public function update(Request $request, InvoiceMessage $message): RedirectResponse
    {
        Gate::authorize('moderateMessages', $message->invoice);

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

        abort_unless($media->model_type === $message->getMorphClass() && (int) $media->model_id === $message->id, 404);

        return $media->toInlineResponse($request);
    }
}
