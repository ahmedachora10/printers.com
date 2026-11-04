<?php

namespace App\Models;

use App\Enums\CustomerTierEnum;
use App\Enums\CustomerTypeEnum;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    use LogsActivity;
    use SoftDeletes;

    /**
     * هوية العميل المشتركة بين الفروع. العميل نفسه صفٌّ لكل فرع (معاملاته ونقاطه
     * ومستواه وائتمانه ومندوبه وعناوينه خاصّة بالفرع)، والصفوف يربطها الجوال:
     * هذه الحقول وحدها تُزامَن بينها.
     */
    public const IDENTITY_FIELDS = ['full_name', 'phone', 'email', 'customer_type', 'company_name', 'tax_number'];

    public const WALK_IN_NAME = 'عميل عابر';

    protected $fillable = [
        'full_name',
        'phone',
        'email',
        'branch_id',
        'customer_type',
        'company_name',
        'tax_number',
        'credit_limit',
        'agent_id',
        'points_balance',
        'cumulative_spend',
        'tier',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'customer_type' => CustomerTypeEnum::class,
        'tier' => CustomerTierEnum::class,
        'credit_limit' => 'decimal:2',
        'cumulative_spend' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        // تعديل الهوية في أي فرع يظهر في كل فروع العميل — بالجوال القديم، فتغيير
        // الجوال نفسه ينتقل. تحديثٌ بالاستعلام بلا أحداث فلا يرتدّ، وسجلّ النشاط
        // على الصف الذي عُدِّل.
        static::saved(function (self $customer) {
            $phone = $customer->getOriginal('phone') ?? $customer->phone;

            if ($phone !== null && $customer->isDirty(self::IDENTITY_FIELDS)) {
                self::query()->where('phone', $phone)->whereKeyNot($customer->id)
                    ->update(Arr::only($customer->getAttributes(), self::IDENTITY_FIELDS));
            }
        });
    }

    /**
     * هوية العميل نفسه من أي فرع (نفس الجوال) فوق المكتوب: ما سُجّل يَغلب، والمكتوب
     * يملأ الفارغ و«عميل عابر» فقط.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function withKnownIdentity(array $attributes): array
    {
        $known = filled($attributes['phone'] ?? null)
            ? self::query()->where('phone', $attributes['phone'])->first()
            : null;

        $identity = array_filter(
            Arr::only($known?->getAttributes() ?? [], self::IDENTITY_FIELDS),
            fn ($value) => $value !== null && $value !== self::WALK_IN_NAME,
        );

        return [...$attributes, ...$identity];
    }

    /**
     * عميل الفاتورة من اسمٍ/جوالٍ مكتوب: صفّه في الفرع، وإلا صفٌّ جديد للفرع يرث
     * هويته من فرعٍ آخر، وإلا عميلٌ جديد.
     */
    public static function resolveWalkIn(int $branchId, string $name, ?string $phone, ?string $taxNumber = null): self
    {
        $customer = $phone === null ? null : self::query()
            ->where('branch_id', $branchId)
            ->where('phone', $phone)
            ->first();

        $customer ??= new self([
            'branch_id' => $branchId,
            'customer_type' => CustomerTypeEnum::Individual,
            'is_active' => true,
        ]);

        $customer->fill(self::withKnownIdentity([
            'full_name' => $name !== '' ? $name : self::WALK_IN_NAME,
            'phone' => $phone,
            'tax_number' => $taxNumber,
        ]))->save();

        return $customer;
    }

    /**
     * الجوال الجديد لا يكون لعميلٍ آخر في أي فرع؛ جوال العميل الحالي (وصفوفه في
     * الفروع الأخرى) مقبول.
     */
    public static function uniquePhoneRule(self $customer): Unique
    {
        return Rule::unique('customers', 'phone')
            ->whereNot('phone', $customer->phone)
            ->whereNull('deleted_at');
    }

    public function getActivitylogOptions(): LogOptions
    {
        // Dirty-only: loyalty earns touch points/spend on every paid invoice —
        // logging full snapshots each time would drown the CRM timeline.
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('customers');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /**
     * Shape this customer for the POS customer picker, including loyalty eligibility.
     * Shared by the POS create screens and the async customer-search endpoint.
     *
     * @param  int  $reservedPoints  النقاط المحجوزة على فواتيره التي لم تُعتمد بعد
     *                               (ResolveAvailablePointsAction): معروضةٌ ومطروحةٌ من
     *                               المتاح، فما يظهر للكاشير هو ما يمكن استبداله فعلاً.
     * @return array<string, mixed>
     */
    public function toPosArray(?LoyaltyConfig $loyalty, bool $loyaltyActive, int $reservedPoints = 0): array
    {
        $eligible = $loyaltyActive
            && $this->customer_type === CustomerTypeEnum::Individual
            && $this->agent_id === null;

        return [
            'id' => $this->id,
            'fullName' => $this->full_name,
            'phone' => $this->phone,
            'taxNumber' => $this->tax_number,
            'agentId' => $this->agent_id,
            'pointsBalance' => (int) $this->points_balance,
            'reservedPoints' => $reservedPoints,
            'availablePoints' => max(0, (int) $this->points_balance - $reservedPoints),
            'tier' => $this->tier->value,
            'tierLabel' => $this->tier->label(),
            'tierDiscountPct' => $eligible ? $loyalty->discountPctForTier($this->tier) : 0.0,
            'loyaltyEligible' => $eligible,
            // تاسك 93: دفتر العناوين يسافر مع بطاقة العميل، فاختياره في نقطة
            // البيع لا يكلّف استعلاماً. تُقرأ من العلاقة المحمَّلة وحدها — بلا
            // تحميلٍ مسبق تعود فارغة ولا تُطلق استعلاماً لكل عميل في القائمة.
            'addresses' => $this->relationLoaded('addresses')
                ? $this->addresses->map(fn (CustomerAddress $address) => [
                    'id' => $address->id,
                    'label' => $address->label,
                    'address' => $address->address,
                    'locationUrl' => $address->location_url,
                    'displayLabel' => $address->displayLabel(),
                    'deliveryZoneId' => $address->delivery_zone_id,
                    'isDefault' => $address->is_default,
                ])->values()->all()
                : [],
        ];
    }

    /**
     * دفتر عناوين العميل (تاسك 93) — الافتراضيّ أولاً فيقع عليه اختيار نقطة
     * البيع بلا بحث.
     *
     * @return HasMany<CustomerAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class)
            ->orderByDesc('is_default')
            ->orderBy('id');
    }

    /** @return HasMany<ProductInvoice, $this> */
    public function productInvoices(): HasMany
    {
        return $this->hasMany(ProductInvoice::class, 'customer_id');
    }

    /** @return HasMany<ServiceInvoice, $this> */
    public function serviceInvoices(): HasMany
    {
        return $this->hasMany(ServiceInvoice::class, 'customer_id');
    }

    /** @return HasMany<LoyaltyTransaction, $this> */
    public function loyaltyTransactions(): HasMany
    {
        return $this->hasMany(LoyaltyTransaction::class);
    }

    /** الأرقام العربية والفارسية ← لاتينية، وما سواها كما هو. */
    public static function latinDigits(string $text): string
    {
        return str_replace(mb_str_split('٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹'), [...range(0, 9), ...range(0, 9)], $text);
    }

    /**
     * الصيغة الموحّدة للجوال، بلا مسافات: السعودي 05XXXXXXXX، والدولي +رمز الدولة
     * (+923029216123). الأرقام العربية تصير لاتينية، ويُحذف كل ما ليس رقماً (مسافات،
     * شرطات، علامات الاتجاه في النصّ الملصوق) إلا + في أوله، و00 في أوله تصير +.
     * ما لا يطابق بعدها يردّه التحقق (PhoneNumber).
     */
    public static function normalizePhone(?string $phone): ?string
    {
        $phone = preg_replace('/[^\d+]/', '', self::latinDigits((string) $phone));
        $international = str_starts_with($phone, '+') || str_starts_with($phone, '00');
        $digits = preg_replace('/^00/', '', preg_replace('/\D/', '', $phone));

        if ($digits === '') {
            return null;
        }

        // الجوال السعودي بأي صيغة (+966 / 00966 / 966 / بلا صفر) ← 05XXXXXXXX.
        $digits = preg_replace('/^966(?=5)/', '0', $digits);

        if (preg_match('/^5\d{8}$/', $digits)) {
            return '0'.$digits;
        }

        return $international && ! str_starts_with($digits, '0') ? '+'.$digits : $digits;
    }

    /** نصّ بحثٍ بلا حروف يُقرأ جوالاً بصيغته الموحّدة. */
    public static function phoneFromTerm(string $term): ?string
    {
        return preg_match('/\p{L}/u', $term) ? null : self::normalizePhone($term);
    }

    /**
     * بحثٌ بالاسم أو الجوال. نصٌّ بلا حروف يُقرأ جوالاً بصيغته الموحّدة، فـ«+966 55 172»
     * تجد 0551728069.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeMatching(Builder $query, string $term): void
    {
        $term = trim($term);
        $phone = self::phoneFromTerm($term);

        $phone !== null ? $query->where('phone', 'like', "%{$phone}%") : $query->where('full_name', 'like', "%{$term}%");
    }

    /**
     * فلاتر شاشة العملاء — تقرؤها الشاشة والتصدير معاً. فلتر الفرع ليس هنا: هو
     * نطاق صلاحية يحسمه المتحكّم قبل أي فلتر.
     *
     * @param  Builder<$this>  $query
     * @param  array<string, mixed>  $filters
     */
    public function scopeFilteredBy(Builder $query, array $filters): void
    {
        $search = $filters['search'] ?? null;

        $query
            ->when(filled($search), fn ($q) => $q->matching($search))
            ->when(filled($filters['tier'] ?? null), fn ($q) => $q->where('tier', $filters['tier']))
            ->when(filled($filters['type'] ?? null), fn ($q) => $q->where('customer_type', $filters['type']))
            ->when(filled($filters['agent_id'] ?? null), fn ($q) => $q->where('agent_id', (int) $filters['agent_id']))
            ->when(filter_var($filters['has_outstanding'] ?? false, FILTER_VALIDATE_BOOLEAN), fn ($q) => $q->whereExists(function ($sub) {
                foreach (['service_invoices', 'product_invoices'] as $table) {
                    if (Schema::hasTable($table)) {
                        $sub->selectRaw('1')->from($table)
                            ->whereColumn("{$table}.customer_id", 'customers.id')
                            ->where("{$table}.status", 'due')
                            ->whereNull("{$table}.deleted_at");

                        return;
                    }
                }
            }));
    }
}
