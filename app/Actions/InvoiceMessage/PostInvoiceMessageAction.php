<?php

namespace App\Actions\InvoiceMessage;

use App\Models\InvoiceMessage;
use App\Models\ServiceInvoice;
use App\Models\User;
use App\Notifications\InvoiceMessagePostedNotification;
use App\Support\BranchNotifiables;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * تاسك 100 — رسالةٌ في المحادثة الداخلية لفاتورة خدمات: من شاشة الفاتورة، أو
 * من خانة «ملاحظات داخلية» في نقطة البيع (رسالةً أولى عند الإنشاء، وجديدةً
 * عند التعديل — لا استبدال).
 *
 * الإشارات لا تُخزَّن: «@الاسم» في النص للعرض، والرموز تُستهلك هنا — تُدخل
 * الموظفَ المُشار إليه مشاركاً في الخيط وتنبّه أصحابها.
 *
 * تاسك 208: الرسالة الخاصة ($privateTo) تذهب لمن أُرسلت إليه وحده — هو وحده
 * يُنبَّه ويُدخل مشاركاً، والإشارات فيها لا أثر لها: لن يراها المُشار إليه.
 */
class PostInvoiceMessageAction
{
    /**
     * @param  list<string>  $mentions  رموز `role:accountant` / `role:branch-admin` / `user:{id}`، مُتحقَّقٌ منها
     * @param  list<UploadedFile>  $attachments
     * @param  string|null  $privateTo  رمزٌ واحد من الصيغة نفسها، مُتحقَّقٌ منه
     */
    public function handle(ServiceInvoice $invoice, User $author, ?string $body, array $mentions = [], array $attachments = [], ?string $privateTo = null): InvoiceMessage
    {
        $body = filled($body) ? trim($body) : null;
        $mentions = $privateTo !== null ? [$privateTo] : $mentions;

        return DB::transaction(function () use ($invoice, $author, $body, $mentions, $attachments, $privateTo) {
            // المؤلفون قبل هذه الرسالة وصاحب الفاتورة يُنبَّهون بردٍّ عام — لا بخاص.
            $watcherIds = $privateTo !== null ? collect() : $invoice->messages()->whereNotNull('user_id')->distinct()->pluck('user_id')->push($invoice->user_id);

            $message = $invoice->messages()->create(['user_id' => $author->id, 'body' => $body, 'private_to' => $privateTo]);

            foreach ($attachments as $file) {
                $message->addMedia($file)->toMediaCollection(InvoiceMessage::ATTACHMENTS);
            }

            InvoiceMessage::markRead($author, $invoice, $message->id);

            $mentionedIds = collect($mentions)
                ->filter(fn (string $token) => str_starts_with($token, 'user:'))
                ->map(fn (string $token) => (int) substr($token, 5));
            $mentionedRoles = collect($mentions)
                ->filter(fn (string $token) => str_starts_with($token, 'role:'))
                ->map(fn (string $token) => substr($token, 5))
                ->values()
                ->all();

            // المُشار إليه يصير مشاركاً — ومنه يأتيه حقّ رؤية الخيط وعدّاد غير
            // المقروء. موضع القراءة 0: كل الخيط جديدٌ عليه.
            DB::table('invoice_thread_participants')->insertOrIgnore($mentionedIds->map(fn (int $id) => [
                'user_id' => $id,
                'service_invoice_id' => $invoice->id,
                'last_read_message_id' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all());

            $recipients = User::query()
                ->whereIn('id', $watcherIds->concat($mentionedIds)->filter())
                ->get()
                ->concat($mentionedRoles === [] ? [] : BranchNotifiables::forBranch((int) $invoice->branch_id, $mentionedRoles))
                ->unique('id')
                ->reject(fn (User $user) => $user->id === $author->id);

            Notification::send($recipients, new InvoiceMessagePostedNotification(
                $invoice->invoice_number,
                $invoice->id,
                $author->name,
                $body,
            ));

            return $message;
        });
    }
}
