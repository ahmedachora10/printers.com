<?php

namespace App\Http\Resources\Activity;

use App\Enums\InvoiceStatusEnum;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\DeliveryProvider;
use App\Models\DeliveryZone;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\ProductInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Refund;
use App\Models\ServiceInvoice;
use App\Models\StockReconciliation;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
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
        'messages_closed_at' => 'إغلاق المحادثة',
        'messages_closed_by' => 'أغلق المحادثة',
        'customer_address_id' => 'عنوان التسليم',
        'target_id' => 'الحساب المستهدف',
        'count' => 'العدد',
        'shortages' => 'نواقص الخامات',
        'lines' => 'السطور',
        'deducted_at' => 'تاريخ الحسم',
    ];

    /** ضوضاءٌ لا تُعرض ضمن الفروق. */
    private const HIDDEN_FIELDS = ['updated_at', 'created_at', 'deleted_at', 'password', 'remember_token'];

    /**
     * السجلّ اليدويّ يكتب `old`/`new` مسطَّحين بلا اسمِ حقل — الوصفُ هو ما يسمّي
     * ما تغيّر.
     */
    private const PAIR_LABELS = [
        'payment method changed' => 'طريقة الدفع',
        'updated internal notes' => 'الملاحظات الداخلية',
    ];

    /**
     * ما يستوجب تمييز الصفّ: المال، والحالة، وطريقة الدفع، ورصيد العميل، وما
     * يُحذف. لا تُخفى بقيّة الصفوف — إنما تُقرأ هذه أولاً عند المراجعة.
     */
    private const SENSITIVE_FIELDS = [
        'payment_method_id', 'طريقة الدفع', 'status', 'total_amount', 'subtotal',
        'coupon_discount', 'agent_discount', 'tier_discount_amount', 'points_discount',
        'employee_commission', 'materials_cost', 'unit_price', 'total', 'amount',
        'points_balance', 'points_redeemed', 'cumulative_spend', 'tier', 'credit_limit',
        'salary', 'base_commission_pct', 'approved_at', 'vat_amount', 'shipping_fee',
    ];

    /** أسماءٌ للمعرّفات، مجموعةً على مستوى الصفحة: ['payment_method_id' => [2 => 'كاش']]. */
    private array $names = [];

    /** @param  array<string, array<int, string>>  $names */
    public function withNames(array $names): static
    {
        $this->names = $names;

        return $this;
    }

    /**
     * `properties` عمودٌ مصبوبٌ Collection في Spatie لا مصفوفة — يُقرأ مصفوفةً
     * مرّةً واحدة هنا، وإلا سقط كلُّ فحص `is_array` عليه صامتاً.
     *
     * @return array<string, mixed>
     */
    private function properties(): array
    {
        $properties = $this->resource->properties;

        return $properties instanceof Collection ? $properties->all() : (array) $properties;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $activity = $this->resource;
        $changes = $this->changes();
        $details = $this->details();

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
            'details' => $details,
            'isSensitive' => $this->isSensitive($changes),
        ];
    }

    /**
     * الحقول التي يقرأ العارض معرّفاتها كأسماء، ومصدرُ كل اسم. تُجمع في
     * المتحكّم مرّةً لكل صفحة (استعلامٌ واحد لكل نوعٍ حاضر) لا لكل صفّ.
     *
     * @return array<string, array{class-string<Model>, string}>
     */
    public static function idFields(): array
    {
        return [
            'payment_method_id' => [PaymentMethod::class, 'name'],
            'customer_id' => [Customer::class, 'full_name'],
            'branch_id' => [Branch::class, 'name'],
            'expense_category_id' => [ExpenseCategory::class, 'name'],
            'supplier_id' => [Supplier::class, 'name'],
            'shipping_provider_id' => [DeliveryProvider::class, 'name'],
            'service_invoice_id' => [ServiceInvoice::class, 'invoice_number'],
            'invoice_id' => [ServiceInvoice::class, 'invoice_number'],
            'user_id' => [User::class, 'name'],
            'delivered_by' => [User::class, 'name'],
            'approved_by' => [User::class, 'name'],
            'cancelled_by' => [User::class, 'name'],
            'created_by' => [User::class, 'name'],
            'target_id' => [User::class, 'name'],
            'impersonator_id' => [User::class, 'name'],
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
     * ما تغيّر، بأشكال `properties` الثلاثة التي يكتبها النظام:
     *
     * 1. النموذج: `old` و`attributes` مصفوفتان. والنماذج هنا تسجّل `fillable`
     *    كاملاً لا المتّسخ وحده، فالمقارنة بينهما هي ما يفرز التغيير من الثبات.
     * 2. السجلّ اليدويّ المسطَّح: `old` و`new` قيمتان مفردتان (طريقة الدفع،
     *    الملاحظات الداخلية) — يسمّيهما الوصف.
     * 3. أزواج `from_x`/`to_x` (ترقية فئة الولاء اليدوية).
     *
     * والإنشاء لا فروق له — كل الحقول جديدة.
     *
     * @return list<array{field: string, label: string, old: string, new: string}>
     */
    private function changes(): array
    {
        $properties = $this->properties();
        $old = $properties['old'] ?? null;
        $new = $properties['attributes'] ?? null;

        if ($this->resource->event === 'created') {
            return [];
        }

        // (2) قيمتان مفردتان: الوصف هو اسم الحقل.
        if (! is_array($new) && (isset($properties['new']) || isset($properties['old'])) && ! is_array($old)) {
            $label = self::PAIR_LABELS[$this->resource->description] ?? 'القيمة';

            return [[
                'field' => $label,
                'label' => $label,
                'old' => $this->display($label, $old),
                'new' => $this->display($label, $properties['new'] ?? null),
            ]];
        }

        $changes = [];

        // (3) from_x ⇐ to_x.
        foreach ($properties as $key => $value) {
            if (! str_starts_with((string) $key, 'from_') || ! array_key_exists('to_'.substr((string) $key, 5), $properties)) {
                continue;
            }

            $field = substr((string) $key, 5);
            $changes[] = [
                'field' => $field,
                'label' => self::FIELD_LABELS[$field] ?? $field,
                'old' => $this->display($field, $value),
                'new' => $this->display($field, $properties['to_'.$field]),
            ];
        }

        // (1) فروق النموذج.
        foreach (is_array($new) ? $new : [] as $field => $value) {
            if (in_array($field, self::HIDDEN_FIELDS, true)) {
                continue;
            }

            $before = is_array($old) ? ($old[$field] ?? null) : null;

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

    /**
     * بقيّة ما في `properties` مما لا مقابلَ قديماً له — مبلغُ الحسم المحذوف،
     * وعددُ المصروفات المعتمَدة، وسببُ الإجراء. يُعرض سطوراً تحت الفروق بدل
     * أن يضيع، فصفٌّ بلا تفاصيل هو ما شكا منه العميل.
     *
     * @return list<array{label: string, value: string}>
     */
    private function details(): array
    {
        $properties = $this->properties();

        if ($properties === []) {
            return [];
        }

        $details = [];

        foreach ($properties as $key => $value) {
            $key = (string) $key;

            // ما استهلكته الفروق أصلاً، وما لا معنى له للقارئ.
            if (in_array($key, ['attributes', 'old', 'new', 'payment_id', 'impersonator_id'], true)
                || str_starts_with($key, 'from_')
                || str_starts_with($key, 'to_')) {
                continue;
            }

            $details[] = [
                'label' => self::FIELD_LABELS[$key] ?? $key,
                'value' => is_array($value) ? count($value).' عنصر' : $this->display($key, $value),
            ];
        }

        return $details;
    }

    /**
     * @param  list<array{field: string, label: string, old: string, new: string}>  $changes
     */
    private function isSensitive(array $changes): bool
    {
        // الدخول والخروج روتينٌ يوميّ؛ الحسّاس في سجلّ الأمان هو الانتحال.
        if ($this->resource->event === 'deleted'
            || ($this->resource->log_name === 'security' && ! in_array($this->resource->description, ['تسجيل الدخول', 'تسجيل الخروج'], true))) {
            return true;
        }

        foreach ($changes as $change) {
            if (in_array($change['field'], self::SENSITIVE_FIELDS, true)) {
                return true;
            }
        }

        return false;
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

        // معرّفٌ باسمه: الأسماء مجموعةٌ لكل الصفحة، فلا استعلام هنا. والمحذوف
        // نهائياً لا اسم له فيبقى رقمه.
        if (isset($this->names[$field]) && is_numeric($value)) {
            return $this->names[$field][(int) $value] ?? '#'.$value;
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
