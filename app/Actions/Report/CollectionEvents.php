<?php

namespace App\Actions\Report;

use App\Enums\InvoiceStatusEnum;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceHelper;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * أحداث التحصيل: كل دفعة بتاريخها، والمسدَّدة عند البيع بإجماليها يوم سدادها،
 * والمرتجع حدثٌ سالب يوم تسجيله. أساس تقرير المبيعات ولوحة التحكم والحوافز
 * (تاسكات 201–203)، فلا يختلف تعريف «المبيعات» بين شاشة وأخرى.
 */
class CollectionEvents
{
    /**
     * The statuses that had money collected on them. A returned invoice stays:
     * its sale belongs to the day it was collected, and the refund is its own
     * negative event on the day the money went back (task 172).
     */
    public const COLLECTED_STATUSES = [
        InvoiceStatusEnum::PAID->value,
        InvoiceStatusEnum::PARTIALLY_PAID->value,
        InvoiceStatusEnum::RETURNED->value,
    ];

    /** Sum of every discount column of one invoice table, under an alias. */
    private function discounts(string $alias): string
    {
        return "({$alias}.tier_discount_amount + {$alias}.coupon_discount + {$alias}.points_discount + {$alias}.agent_discount + {$alias}.manual_discount)";
    }

    /**
     * تاسك 93 — رسم التوصيل، مقروءاً من جدول الفاتورة أو صفراً.
     *
     * ⚠️ التقرير اتحادٌ بين جدولَي الفواتير، وفاتورة المنتجات بلا عمود شحن في
     * هذه المرحلة: لا بدّ من `0` صريحة على فرعها وإلا اختلّت أعمدة الاتحاد.
     */
    private function shipping(string $table, string $alias): string
    {
        return $table === 'service_invoices' ? "{$alias}.shipping_fee" : '0';
    }

    /**
     * وما رُدّ منه على المرتجع — بقرار المحاسب المكتوب لا باستنتاجٍ نسبيّ.
     */
    private function shippingRefunded(string $table, string $alias): string
    {
        return $table === 'service_invoices' ? "{$alias}.shipping_refunded" : '0';
    }

    /**
     * Every collection event of one invoice table, with the scope filters
     * applied — the base of every figure in this report.
     *
     * Two sources are unioned:
     *  A. one row per recorded payment (deposit or instalment), dated by that
     *     payment and carrying its own payment method;
     *  B. one row per paid invoice that has no payment rows at all — settled in
     *     one go at the till — worth its whole total and dated by paid_at;
     *  C. one *negative* row per refund, dated by the day the money went back.
     *
     * Because DB::table() bypasses the SoftDeletes global scope, deleted rows
     * are excluded explicitly.
     *
     * @param  array<string, mixed>  $scope
     */
    public function handle(string $table, array $scope): Builder
    {
        $morphClass = self::morphClassFor($table);
        $discounts = $this->discounts('i');
        $shipping = $this->shipping($table, 'i');
        $shippingRefunded = $this->shippingRefunded($table, 'r');
        // حصة الدفعة من الفاتورة: تُوزَّع بها أرقامُ الفاتورة (الفرعي، الخصومات،
        // الضريبة) فتبقى المعادلة متسقة على التحصيل الجزئي. الضرب في 1.0 يفرض
        // قسمةً عشرية — SQLite يقسم الأعداد الصحيحة قسمةً صحيحة فتصير الحصة صفراً.
        $share = '(p.amount * 1.0) / NULLIF(i.total_amount, 0)';

        $payments = DB::table('invoice_payments as p')
            ->join($table.' as i', 'i.id', '=', 'p.invoice_id')
            ->where('p.invoice_type', $morphClass)
            ->whereNull('i.deleted_at')
            ->whereIn('i.status', self::COLLECTED_STATUSES)
            ->select([
                DB::raw('i.id as invoice_id'),
                // تاسك 106: الدفعة تحمل إيصالها، فيُعرف الحدث بها.
                DB::raw('p.id as payment_id'),
                DB::raw('i.invoice_number as invoice_number'),
                // تاسك 178: تاريخ إنشاء الفاتورة بجانب يوم تحصيلها.
                DB::raw('i.created_at as invoice_created_at'),
                DB::raw('i.branch_id as branch_id'),
                DB::raw('i.user_id as user_id'),
                DB::raw('COALESCE(p.payment_method_id, i.payment_method_id) as payment_method_id'),
                DB::raw('p.paid_at as realized_at'),
                DB::raw('p.amount as realized'),
                DB::raw("i.subtotal * ({$share}) as subtotal_share"),
                DB::raw("{$discounts} * ({$share}) as discounts_share"),
                DB::raw("i.vat_amount * ({$share}) as vat_share"),
                // التوصيل يتبع الحصّة كبقية أرقام الفاتورة: دفعةٌ جزئية تحمل
                // جزءاً من الشحن كما تحمل جزءاً من الضريبة.
                DB::raw("{$shipping} * ({$share}) as shipping_share"),
                DB::raw('0 as refunded'),
            ]);

        $direct = DB::table($table.' as i')
            ->whereNull('i.deleted_at')
            // المرتجعة التي سُدّدت عند البيع تبقى بيعاً يوم سدادها (تاسك 172)؛
            // والمرتجعة وهي آجلة لا paid_at لها فيُسقطها whereNotNull أدناه.
            ->whereIn('i.status', [InvoiceStatusEnum::PAID->value, InvoiceStatusEnum::RETURNED->value])
            ->whereNotExists(fn ($q) => $q->from('invoice_payments as p')
                ->where('p.invoice_type', $morphClass)
                ->whereColumn('p.invoice_id', 'i.id'))
            ->select([
                DB::raw('i.id as invoice_id'),
                DB::raw('NULL as payment_id'),
                DB::raw('i.invoice_number as invoice_number'),
                DB::raw('i.created_at as invoice_created_at'),
                DB::raw('i.branch_id as branch_id'),
                DB::raw('i.user_id as user_id'),
                DB::raw('i.payment_method_id as payment_method_id'),
                DB::raw('i.paid_at as realized_at'),
                DB::raw('i.total_amount as realized'),
                DB::raw('i.subtotal as subtotal_share'),
                DB::raw("{$discounts} as discounts_share"),
                DB::raw('i.vat_amount as vat_share'),
                DB::raw("{$shipping} as shipping_share"),
                DB::raw('0 as refunded'),
            ]);

        // C. المرتجعات — أحداثٌ سالبة بحصّتها من الفاتورة، مؤرَّخةٌ بيوم الإرجاع،
        // جزئيةً كانت أو كاملة: بيعُ الفاتورة المرتجعة بقي في الفرعين أعلاه.
        $refundShare = '(r.amount * 1.0) / NULLIF(i.total_amount, 0)';

        $refunds = DB::table('refunds as r')
            ->join($table.' as i', 'i.id', '=', 'r.invoice_id')
            ->where('r.invoice_type', $morphClass)
            ->whereNull('r.deleted_at')
            ->whereNull('i.deleted_at')
            ->whereIn('i.status', self::COLLECTED_STATUSES)
            ->select([
                DB::raw('i.id as invoice_id'),
                DB::raw('NULL as payment_id'),
                DB::raw('i.invoice_number as invoice_number'),
                DB::raw('i.created_at as invoice_created_at'),
                DB::raw('i.branch_id as branch_id'),
                // يُنسب المرتجع إلى منشئ الفاتورة لا إلى من سجّله: الأثر على
                // مبيعات ذلك الموظف. نفس عُرف التقرير اليومي.
                DB::raw('i.user_id as user_id'),
                // تاسك 131: بطريقة الردّ المكتوبة على المرتجع — منها خرج المال،
                // فنقدُ الدرج يُنقَص بالمرتجع النقدي وحده. والمرتجع القديم بلا
                // طريقة يبقى على طريقة فاتورته كما كان.
                DB::raw('COALESCE(r.payment_method_id, i.payment_method_id) as payment_method_id'),
                DB::raw('r.created_at as realized_at'),
                DB::raw('-r.amount as realized'),
                DB::raw("-i.subtotal * ({$refundShare}) as subtotal_share"),
                DB::raw("-{$discounts} * ({$refundShare}) as discounts_share"),
                DB::raw("-i.vat_amount * ({$refundShare}) as vat_share"),
                // ما رُدّ من الشحن مكتوبٌ على صفّ المرتجع بقرار المحاسب، فلا
                // يُوزَّع نسبياً كبقية الأرقام: مرتجعٌ لم يُردّ شحنه لا يُنقص
                // جملة التوصيل، ومرتجعٌ رُدّ يُنقصها بما رُدّ بالضبط.
                DB::raw("-{$shippingRefunded} as shipping_share"),
                DB::raw('r.amount as refunded'),
            ]);

        return DB::query()
            ->fromSub($payments->unionAll($direct)->unionAll($refunds), 'events')
            ->whereNotNull('events.realized_at')
            ->when($scope['branchIds'], fn ($q) => $q->whereIn('events.branch_id', $scope['branchIds']))
            ->when($scope['from'], fn ($q) => $q->where('events.realized_at', '>=', $scope['from']))
            ->when($scope['to'], fn ($q) => $q->where('events.realized_at', '<=', $scope['to']));
    }

    /**
     * تاسك 204 — الأحداث نفسها موزَّعةً على المشاركين: كل حدث فاتورة خدمة
     * يُقسم بين صاحبها ومساعديها بحصصهم (ServiceInvoiceHelper::salesShares)،
     * فـ`events.user_id` هو من تُنسب إليه المبيعات و`events.realized` نصيبه.
     * فواتير المنتجات بلا مساعدين فتبقى لصاحبها كما هي.
     *
     * @param  array<string, mixed>  $scope
     */
    public function perEmployee(string $table, array $scope): Builder
    {
        $events = $this->handle($table, $scope);

        if ($table !== 'service_invoices') {
            return $events;
        }

        return DB::query()->fromSub(
            $events->joinSub(ServiceInvoiceHelper::salesShares(), 'sp', 'sp.invoice_id', '=', 'events.invoice_id')
                ->select([
                    'events.invoice_id',
                    'events.branch_id',
                    'events.realized_at',
                    'sp.user_id',
                    DB::raw('events.realized * sp.factor as realized'),
                ]),
            'events',
        );
    }

    /** The morph class stored on invoice_payments for one invoice table. */
    public static function morphClassFor(string $table): string
    {
        return $table === 'product_invoices' ? ProductInvoice::class : ServiceInvoice::class;
    }
}
