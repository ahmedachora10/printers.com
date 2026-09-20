<?php

namespace App\Http\Resources\Activity;

use App\Enums\InvoiceStatusEnum;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\DeliveryProvider;
use App\Models\DeliveryZone;
use App\Models\Expense;
use App\Models\ProductInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Refund;
use App\Models\ServiceInvoice;
use App\Models\StockReconciliation;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Activitylog\Models\Activity;

/**
 * تاسك 115: صفٌّ واحد من `activity_log` بصيغةٍ مقروءة — الفاعل، والجملة العربية،
 * والسجلّ المتأثّر برابطه إن وُجد، والحقول التي تغيّرت (قديم ⇐ جديد).
 *
 * الجملة تُشتقّ ولا تُكتب: الاعتماد والإلغاء والإرجاع كلّها `updated` على النموذج،
 * فيقرؤها العارض من انتقال `status` بدل إضافة كتابةٍ ثانية إلى مسار الحفظ — وبهذا
 * تُقرأ السجلّات القديمة كما تُقرأ الجديدة.
 */
class ActivityResource extends JsonResource
{
    /** @var Activity */
    public $resource;

    /** أقسام السجلّ كما تظهر للمستخدم. */
    public const LOG_LABELS = [
        'sales' => 'المبيعات',
        'invoices' => 'الفواتير',
        'expenses' => 'المصروفات',
        'customers' => 'العملاء',
        'customer' => 'العملاء',
        'inventory' => 'المخزون',
        'refunds' => 'المرتجعات',
        'shipping' => 'التوصيل',
        'incentives' => 'الحوافز والحسومات',
        'branches' => 'الفروع',
        'security' => 'الأمان',
        'deploy' => 'النشر',
    ];

    /** أوصافٌ كُتبت بالإنجليزية في مسارها الأصلي. */
    private const DESCRIPTIONS = [
        'created' => 'أنشأ',
        'updated' => 'عدّل',
        'deleted' => 'حذف',
        'restored' => 'استعاد',
        'started impersonation' => 'سجّل الدخول كمستخدم آخر',
        'stopped impersonation' => 'أنهى الدخول كمستخدم آخر',
        'updated internal notes' => 'عدّل الملاحظات الداخلية على',
        'updated own branch profile' => 'عدّل بيانات فرعه',
        'edited invoice message' => 'عدّل رسالةً على',
        'deleted invoice message' => 'حذف رسالةً من',
        'payment method changed' => 'غيّر طريقة الدفع على',
    ];

    /** نوع السجلّ المتأثّر بالعربية. */
    private const SUBJECT_TYPES = [
        ServiceInvoice::class => 'فاتورة خدمة',
        ProductInvoice::class => 'فاتورة منتجات',
        Expense::class => 'مصروف',
        Customer::class => 'عميل',
        CustomerAddress::class => 'عنوان عميل',
        User::class => 'مستخدم',
        Branch::class => 'فرع',
        Supplier::class => 'مورّد',
        PurchaseOrder::class => 'أمر شراء',
        PurchaseRequest::class => 'طلب شراء',
        Refund::class => 'مرتجع',
        StockReconciliation::class => 'جرد مخزون',
        DeliveryProvider::class => 'مزوّد توصيل',
        DeliveryZone::class => 'نطاق توصيل',
    ];

    /**
     * أسماء الحقول عربياً. خريطةٌ واحدة لكل النماذج: الأعمدة المشتركة
     * (`status`, `total_amount`, `notes`…) تحمل المعنى نفسه أينما وردت.
     */
    private const FIELD_LABELS = [
        'invoice_number' => 'رقم الفاتورة',
        'status' => 'الحالة',
        'subtotal' => 'المجموع قبل الضريبة',
        'total_amount' => 'الإجمالي',
        'vat_amount' => 'الضريبة',
        'vat_pct' => 'نسبة الضريبة',
        'employee_commission' => 'عمولة الموظف',
        'materials_cost' => 'تكلفة الخامات',
        'coupon_discount' => 'خصم الكوبون',
        'agent_discount' => 'خصم المندوب',
        'tier_discount_amount' => 'خصم فئة الولاء',
        'points_redeemed' => 'النقاط المستبدلة',
        'points_discount' => 'قيمة النقاط',
        'shipping_fee' => 'رسوم التوصيل',
        'payment_method_id' => 'طريقة الدفع',
        'customer_id' => 'العميل',
        'branch_id' => 'الفرع',
        'user_id' => 'المستخدم',
        'paid_at' => 'تاريخ السداد',
        'delivery_at' => 'موعد التسليم',
        'delivered_at' => 'تاريخ التسليم',
        'cancellation_reason' => 'سبب الإلغاء',
        'notes' => 'ملاحظات العميل',
        'internal_notes' => 'ملاحظات داخلية',
        'name' => 'الاسم',
        'full_name' => 'الاسم',
        'phone' => 'الهاتف',
        'email' => 'البريد الإلكتروني',
        'is_active' => 'الحالة',
        'qty' => 'الكمية',
        'unit_price' => 'سعر الوحدة',
        'total' => 'الإجمالي',
        'date' => 'التاريخ',
        'paid_from' => 'مصدر الدفع',
        'expense_category_id' => 'فئة المصروف',
        'service_invoice_id' => 'الفاتورة المرتبطة',
        'supplier_name' => 'المورّد',
        'receipt_reference' => 'مرجع الإيصال',
        'approved_at' => 'الاعتماد',
        'amount' => 'المبلغ',
        'reason' => 'السبب',
        'tier' => 'فئة الولاء',
        'points_balance' => 'رصيد النقاط',
        'cumulative_spend' => 'الإنفاق التراكمي',
        'delivered_by' => 'سلّمها',
        'approved_by' => 'اعتمدها',
        'cancelled_by' => 'ألغاها',
        'company_name' => 'اسم الشركة',
        'customer_type' => 'نوع العميل',
        'sku' => 'الرمز',
        'selling_price' => 'سعر البيع',
        'cost_price' => 'سعر التكلفة',
        'credit_limit' => 'الحد الائتماني',
        'salary' => 'الراتب',
        'base_commission_pct' => 'نسبة العمولة',
    ];

    /** ضوضاءٌ لا تُعرض ضمن الفروق. */
    private const HIDDEN_FIELDS = ['updated_at', 'created_at', 'deleted_at', 'password', 'remember_token'];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $activity = $this->resource;
        $changes = $this->changes();

        return [
            'id' => $activity->id,
            'logName' => $activity->log_name,
            'logLabel' => self::LOG_LABELS[$activity->log_name] ?? $activity->log_name,
            'action' => $this->action($changes),
            'causerId' => $activity->causer_id,
            'causerName' => $activity->causer?->name ?? 'النظام',
            'subjectType' => self::SUBJECT_TYPES[$activity->subject_type] ?? null,
            'subjectLabel' => $this->subjectLabel(),
            'subjectUrl' => $this->subjectUrl(),
            'date' => $activity->created_at?->format('Y-m-d'),
            'time' => $activity->created_at?->format('H:i'),
            'at' => $activity->created_at?->format('d/m/Y H:i'),
            'changes' => $changes,
        ];
    }

    /**
     * الجملة: انتقالُ حالة الفاتورة أولاً (اعتماد/إلغاء/إرجاع)، ثم الوصف المترجَم،
     * ثم الوصف كما كُتب — فالسجلّات اليدوية عربيةٌ أصلاً في معظمها.
     *
     * @param  list<array{field: string, label: string, old: string, new: string}>  $changes
     */
    private function action(array $changes): string
    {
        $status = collect($changes)->firstWhere('field', 'status');

        if ($status && $this->isInvoice()) {
            $to = InvoiceStatusEnum::tryFrom($this->resource->properties['attributes']['status'] ?? '');

            $verb = match ($to) {
                InvoiceStatusEnum::PAID => 'اعتمد',
                InvoiceStatusEnum::CANCELLED => 'ألغى',
                InvoiceStatusEnum::RETURNED => 'أرجع',
                InvoiceStatusEnum::PARTIALLY_PAID => 'استلم دفعةً على',
                default => null,
            };

            if ($verb) {
                return $verb;
            }
        }

        return self::DESCRIPTIONS[$this->resource->description] ?? $this->resource->description;
    }

    private function isInvoice(): bool
    {
        return in_array($this->resource->subject_type, [ServiceInvoice::class, ProductInvoice::class], true);
    }

    /**
     * اسم السجلّ المتأثّر كما يعرفه المستخدم. السجلّ المحذوف نهائياً لا يُحمّل،
     * فيبقى نوعه ومعرّفه وحدهما.
     */
    private function subjectLabel(): ?string
    {
        $subject = $this->resource->subject;

        if (! $subject) {
            return $this->resource->subject_id ? '#'.$this->resource->subject_id : null;
        }

        foreach (['invoice_number', 'po_number', 'full_name', 'name', 'name_ar', 'supplier_name'] as $field) {
            if (filled($subject->{$field} ?? null)) {
                return (string) $subject->{$field};
            }
        }

        return '#'.$subject->getKey();
    }

    /** رابط السجلّ المتأثّر — للأنواع التي لها شاشة عرضٍ وحدها. */
    private function subjectUrl(): ?string
    {
        $id = $this->resource->subject_id;

        if (! $id) {
            return null;
        }

        return match ($this->resource->subject_type) {
            ServiceInvoice::class => route('invoices.show', ['type' => 'service', 'id' => $id]),
            ProductInvoice::class => route('invoices.show', ['type' => 'product', 'id' => $id]),
            Customer::class => route('customers.show', $id),
            User::class => route('users.show', $id),
            Branch::class => route('branches.show', $id),
            PurchaseOrder::class => route('inventory.purchase-orders.show', $id),
            StockReconciliation::class => route('inventory.stock-reconciliations.show', $id),
            default => null,
        };
    }

    /**
     * الحقول التي تغيّرت فعلاً. النماذج هنا تسجّل `fillable` كاملاً لا المتّسخ
     * وحده، فالمقارنة بين `old` و`attributes` هي ما يفرز التغيير من الثبات.
     * والإنشاء لا فروق له — كل الحقول جديدة.
     *
     * ponytail: المعرّفات تُعرض أرقاماً (فئة، طريقة دفع…)؛ ترجمتها إلى أسماء
     * تعني استعلاماً لكل نوعٍ في الصفحة — يُضاف إن طلبه العميل.
     *
     * @return list<array{field: string, label: string, old: string, new: string}>
     */
    private function changes(): array
    {
        $properties = $this->resource->properties;
        $new = $properties['attributes'] ?? null;

        if ($this->resource->event === 'created' || ! is_array($new)) {
            return [];
        }

        $old = $properties['old'] ?? [];

        if (! is_array($old)) {
            return [];
        }

        $changes = [];

        foreach ($new as $field => $value) {
            if (in_array($field, self::HIDDEN_FIELDS, true)) {
                continue;
            }

            $before = $old[$field] ?? null;

            if ($this->display($field, $before) === $this->display($field, $value)) {
                continue;
            }

            $changes[] = [
                'field' => $field,
                'label' => self::FIELD_LABELS[$field] ?? $field,
                'old' => $this->display($field, $before),
                'new' => $this->display($field, $value),
            ];
        }

        return $changes;
    }

    /** قيمةٌ معروضة: الحالة بوصفها، والمنطقي نعم/لا، والفارغ شَرطة. */
    private function display(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'نعم' : 'لا';
        }

        if ($field === 'status' && is_string($value)) {
            return InvoiceStatusEnum::tryFrom($value)?->label() ?? $value;
        }

        if ($field === 'is_active') {
            return $value ? 'نشط' : 'غير نشط';
        }

        // التواريخ تُخزَّن ISO؛ تُقرأ d/m/Y، والوقت معها إن كان طابعاً زمنياً.
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}([ T]|$)/', $value)) {
            // الطوابع في `properties` مخزَّنة UTC خاماً، بخلاف `created_at`
            // الذي يمرّ بتحويل النموذج — فتُوحَّد على توقيت العرض.
            $date = Carbon::parse($value)->timezone(config('app.timezone'));

            return $date->format($date->isStartOfDay() ? 'd/m/Y' : 'd/m/Y H:i');
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE) ?: '—';
        }

        return (string) $value;
    }
}
