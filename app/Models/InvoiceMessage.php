<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * تاسك 100 — رسالةٌ في المحادثة الداخلية لفاتورة خدمات. لا تُستبدل: الجديدة
 * صفٌّ جديد. تعديلها وحذفها (الناعم) لمدير الفرع والسوبر أدمن وحدهما، والنص
 * القديم ومن فعل ذلك في activity_log — لا أعمدة *_by مكرَّرة هنا.
 *
 * لا تصل أي حمولة طباعة: الخيط prop مستقلّ على شاشة الفاتورة لا حقلٌ في
 * InvoiceResource.
 */
class InvoiceMessage extends Model implements HasMedia
{
    use InteractsWithMedia, SoftDeletes;

    public const ATTACHMENTS = 'attachments';

    public const LOG_EDITED = 'edited invoice message';

    public const LOG_DELETED = 'deleted invoice message';

    protected $fillable = [
        'service_invoice_id',
        'user_id',
        'body',
        'actioned_at',
        'actioned_by',
        'edited_at',
    ];

    protected $casts = [
        'actioned_at' => 'datetime',
        'edited_at' => 'datetime',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::ATTACHMENTS)
            ->useDisk('local')
            ->acceptsMimeTypes(ServiceInvoice::RECEIPT_MIME_TYPES);
    }

    /** @return BelongsTo<ServiceInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ServiceInvoice::class, 'service_invoice_id');
    }

    /** NULL = ملاحظة التاسك 95 المُرحَّلة. @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actionedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actioned_by');
    }

    /**
     * عدد الرسائل غير المقروءة لكل فاتورة: بعد موضع قراءة المستخدم، ليست منه.
     * الملاحظة المُرحَّلة (بلا مؤلف) لا تُعدّ جديدةً على أحد — وإلا حملت كل
     * فاتورةٍ لها ملاحظة قديمة بادجاً عند كل مراجع يوم الترحيل.
     *
     * @param  list<int>  $invoiceIds
     * @return array<int, int> service_invoice_id ⇒ العدد، والصفر غائب
     */
    public static function unreadCounts(User $user, array $invoiceIds): array
    {
        if ($invoiceIds === []) {
            return [];
        }

        return static::query()
            ->leftJoin('invoice_thread_participants as p', fn ($join) => $join
                ->on('p.service_invoice_id', '=', 'invoice_messages.service_invoice_id')
                ->where('p.user_id', $user->id))
            ->whereIn('invoice_messages.service_invoice_id', $invoiceIds)
            ->whereNotNull('invoice_messages.user_id')
            ->where('invoice_messages.user_id', '!=', $user->id)
            ->whereRaw('invoice_messages.id > coalesce(p.last_read_message_id, 0)')
            ->groupBy('invoice_messages.service_invoice_id')
            ->selectRaw('invoice_messages.service_invoice_id, count(*) as unread')
            ->pluck('unread', 'service_invoice_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /** يقدّم موضع قراءة المستخدم إلى $messageId (ويجعله مشاركاً إن لم يكن). */
    public static function markRead(User $user, ServiceInvoice $invoice, int $messageId): void
    {
        DB::table('invoice_thread_participants')->upsert(
            [[
                'user_id' => $user->id,
                'service_invoice_id' => $invoice->id,
                'last_read_message_id' => $messageId,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['user_id', 'service_invoice_id'],
            ['last_read_message_id', 'updated_at'],
        );
    }

    /**
     * من يُشار إليه في خيط هذه الفاتورة: الدوران، ثم مستخدمو فرعها النشطون —
     * منها تُبنى قائمة @ في الواجهة وقاعدة التحقق على الخادم معاً.
     *
     * @return Collection<int, array{token: string, label: string}>
     */
    public static function mentionables(ServiceInvoice $invoice): Collection
    {
        // مدير الفرع مالكُه (branches.owner_id) لا صاحب users.branch_id.
        $ownerId = $invoice->branch?->owner_id;

        $users = User::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('branch_id', $invoice->branch_id)
                ->when($ownerId, fn ($q) => $q->orWhere('id', $ownerId)))
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['branch-admin', 'accountant', 'employee']))
            ->orderBy('name')
            ->get(['id', 'name']);

        return collect([
            ['token' => 'role:accountant', 'label' => 'المحاسب'],
            ['token' => 'role:branch-admin', 'label' => 'مدير الفرع'],
        ])->concat($users->map(fn (User $user) => ['token' => "user:{$user->id}", 'label' => $user->name]));
    }
}
