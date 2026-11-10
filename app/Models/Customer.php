<?php

namespace App\Models;

use App\Enums\CustomerTierEnum;
use App\Enums\CustomerTypeEnum;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
     * هوية العميل — واحدةٌ لكل الفروع. ما يخصّ كل فرع (نقاط، مستوى، إنفاق، ائتمان،
     * مندوب، ملاحظات، نشاط) في حسابه هناك: CustomerBranch.
     */
    public const IDENTITY_FIELDS = ['full_name', 'phone', 'email', 'customer_type', 'company_name', 'tax_number'];

    public const WALK_IN_NAME = 'عميل عابر';

    protected $fillable = self::IDENTITY_FIELDS;

    protected $casts = [
        'customer_type' => CustomerTypeEnum::class,
    ];

    /** العميل بجواله، والمحذوف منه يعود بإعادة تسجيله (الجوال فريدٌ وإن حُذف). */
    public static function forPhone(string $phone): self
    {
        $customer = self::withTrashed()->firstOrNew(['phone' => $phone]);
        $customer->deleted_at = null;

        return $customer;
    }

    /**
     * يملأ الهوية من المكتوب: العميل الجديد يأخذه كلّه، والمسجَّل لا يُكتب فوق
     * ما سُجّل له — يُملأ الفارغ و«عميل عابر» فقط.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function fillIdentity(array $attributes): static
    {
        foreach (Arr::only($attributes, self::IDENTITY_FIELDS) as $key => $value) {
            $current = $this->exists ? $this->getAttribute($key) : null;

            if (filled($value) && ($current === null || $current === self::WALK_IN_NAME)) {
                $this->setAttribute($key, $value);
            }
        }

        return $this;
    }

    /**
     * عميل الفاتورة من اسمٍ/جوالٍ مكتوب: العميل بجواله وإلا عميلٌ جديد، مربوطاً
     * بفرع الفاتورة.
     */
    public static function resolveWalkIn(int $branchId, string $name, ?string $phone, ?string $taxNumber = null): self
    {
        $customer = $phone === null ? new self : self::forPhone($phone);

        $customer->fillIdentity([
            'full_name' => $name !== '' ? $name : self::WALK_IN_NAME,
            'phone' => $phone,
            'tax_number' => $taxNumber,
            'customer_type' => CustomerTypeEnum::Individual,
        ])->save();

        $customer->attachTo($branchId);

        return $customer;
    }

    /** الجوال لا يكون لعميلٍ آخر (والمحذوف داخلٌ في الفهرس الفريد). */
    public static function uniquePhoneRule(self $customer): Unique
    {
        return Rule::unique('customers', 'phone')->ignore($customer->id);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('customers');
    }

    /** @return HasMany<CustomerBranch, $this> */
    public function accounts(): HasMany
    {
        return $this->hasMany(CustomerBranch::class);
    }

    /**
     * حسابه في الفرع، أو null إن لم يكن من عملائه. يقرأ العلاقة المحمَّلة إن وُجدت،
     * فقوائم نقطة البيع لا تُطلق استعلاماً لكل عميل.
     */
    public function accountFor(?int $branchId): ?CustomerBranch
    {
        return $this->relationLoaded('accounts')
            ? $this->accounts->firstWhere('branch_id', $branchId)
            : $this->accounts()->where('branch_id', $branchId)->first();
    }

    /**
     * يربطه بالفرع (أول فاتورة له هناك أو إضافته من شاشة العملاء) ويعيد حسابه.
     *
     * @param  array<string, mixed>  $values
     */
    public function attachTo(int $branchId, array $values = []): CustomerBranch
    {
        $account = $this->accounts()->firstOrCreate(['branch_id' => $branchId], $values);

        if ($this->relationLoaded('accounts') && $account->wasRecentlyCreated) {
            $this->accounts->push($account);
        }

        return $account;
    }

    /**
     * ما تعرضه الشاشات من حساباته المحمَّلة: حساب فرع المستخدم كما هو، وللسوبر
     * أدمن مجموعُ فروعه — النقاط والإنفاق مجموعان، والمستوى أعلاها، ونشطٌ إن نشط
     * في فرعٍ واحد، والائتمان والمندوب والملاحظات حين يكون فرعاً واحداً.
     *
     * @return array{points_balance: int, cumulative_spend: float, tier: CustomerTierEnum, is_active: bool, credit_limit: ?string, agent_id: ?int, notes: ?string, account: ?CustomerBranch}
     */
    public function accountsSummary(): array
    {
        $accounts = $this->accounts;
        $single = $accounts->count() === 1 ? $accounts->first() : null;

        return [
            'points_balance' => (int) $accounts->sum('points_balance'),
            'cumulative_spend' => round((float) $accounts->sum('cumulative_spend'), 2),
            'tier' => $accounts->pluck('tier')->sortByDesc(fn (CustomerTierEnum $tier) => $tier->rank())->first() ?? CustomerTierEnum::None,
            'is_active' => $accounts->contains('is_active', true),
            'credit_limit' => $single?->credit_limit,
            'agent_id' => $single?->agent_id,
            'notes' => $single?->notes,
            'account' => $single,
        ];
    }

    /**
     * عملاء الفرع.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeInBranch(Builder $query, ?int $branchId): void
    {
        $query->whereHas('accounts', fn ($q) => $q->where('branch_id', $branchId));
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
    public function toPosArray(?int $branchId, ?LoyaltyConfig $loyalty, bool $loyaltyActive, int $reservedPoints = 0): array
    {
        // حسابه في فرع نقطة البيع. غير المربوط بالفرع بعدُ (وُجد بجواله الكامل)
        // يُعرض بلا نقاط ولا مستوى، وتربطه الفاتورة عند حفظها.
        $account = $this->accountFor($branchId);
        $tier = $account?->tier ?? CustomerTierEnum::None;
        $points = (int) $account?->points_balance;

        $eligible = $loyaltyActive
            && $this->customer_type === CustomerTypeEnum::Individual
            && $account?->agent_id === null;

        return [
            'id' => $this->id,
            'linked' => $account !== null,
            'fullName' => $this->full_name,
            'phone' => $this->phone,
            'taxNumber' => $this->tax_number,
            'agentId' => $account?->agent_id,
            'pointsBalance' => $points,
            'reservedPoints' => $reservedPoints,
            'availablePoints' => max(0, $points - $reservedPoints),
            'tier' => $tier->value,
            'tierLabel' => $tier->label(),
            'tierDiscountPct' => $eligible ? $loyalty->discountPctForTier($tier) : 0.0,
            'loyaltyEligible' => $eligible,
            // تاسك 93: دفتر العناوين يسافر مع بطاقة العميل، فاختياره في نقطة
            // البيع لا يكلّف استعلاماً. تُقرأ من العلاقة المحمَّلة وحدها — بلا
            // تحميلٍ مسبق تعود فارغة ولا تُطلق استعلاماً لكل عميل في القائمة.
            'addresses' => $this->relationLoaded('addresses')
                ? $this->addresses->where('branch_id', $branchId)->map(fn (CustomerAddress $address) => [
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
    public function scopeFilteredBy(Builder $query, array $filters, ?int $branchId = null): void
    {
        $search = $filters['search'] ?? null;
        // المستوى والمندوب من حسابه في الفرع المحدَّد (أو أيّ فرع للسوبر أدمن بلا فرع).
        $account = fn (string $column, mixed $value) => fn ($q) => $q->whereHas('accounts', fn ($a) => $a
            ->when($branchId, fn ($a) => $a->where('branch_id', $branchId))
            ->where($column, $value));

        $query
            ->when(filled($search), fn ($q) => $q->matching($search))
            ->when(filled($filters['tier'] ?? null), $account('tier', $filters['tier'] ?? null))
            ->when(filled($filters['type'] ?? null), fn ($q) => $q->where('customer_type', $filters['type']))
            ->when(filled($filters['agent_id'] ?? null), $account('agent_id', (int) ($filters['agent_id'] ?? 0)))
            ->when(filter_var($filters['has_outstanding'] ?? false, FILTER_VALIDATE_BOOLEAN), fn ($q) => $q->whereExists(function ($sub) use ($branchId) {
                foreach (['service_invoices', 'product_invoices'] as $table) {
                    if (Schema::hasTable($table)) {
                        $sub->selectRaw('1')->from($table)
                            ->whereColumn("{$table}.customer_id", 'customers.id')
                            ->when($branchId, fn ($s) => $s->where("{$table}.branch_id", $branchId))
                            ->where("{$table}.status", 'due')
                            ->whereNull("{$table}.deleted_at");

                        return;
                    }
                }
            }));
    }
}
