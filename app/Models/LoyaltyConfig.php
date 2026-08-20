<?php

namespace App\Models;

use App\Enums\CustomerTierEnum;
use Database\Factories\LoyaltyConfigFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyConfig extends Model
{
    /** @use HasFactory<LoyaltyConfigFactory> */
    use HasFactory;

    protected $table = 'loyalty_config';

    protected $fillable = [
        'branch_id',
        'earning_rate',
        'redemption_rate',
        'min_redemption_points',
        'expiry_months',
        'bronze_threshold',
        'silver_threshold',
        'gold_threshold',
        'bronze_discount_pct',
        'silver_discount_pct',
        'gold_discount_pct',
        'is_active',
    ];

    /**
     * القيم الافتراضية مكرّرة هنا عمداً رغم وجودها على أعمدة الجدول: صفٌّ يُنشئه
     * `firstOrCreate` يعود بخصائص **فارغة** في الذاكرة — قيم الجدول الافتراضية
     * لا تُقرأ إلا بجلبٍ جديد. وكان أثر ذلك أن أول فاتورة مدفوعة في فرعٍ جديد لا
     * تكسب نقاطاً إطلاقاً (`! $config->is_active` تصدق على null)، ولولا هذه
     * القائمة لصارت حدود الفئات أصفاراً فاستحقّ كلُّ عميلٍ الفئة الذهبية.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'earning_rate' => 1,
        'redemption_rate' => 100,
        'min_redemption_points' => 500,
        'expiry_months' => null,
        'bronze_threshold' => 500,
        'silver_threshold' => 2000,
        'gold_threshold' => 5000,
        'bronze_discount_pct' => 2,
        'silver_discount_pct' => 5,
        'gold_discount_pct' => 8,
        'is_active' => true,
    ];

    protected $casts = [
        'earning_rate' => 'decimal:4',
        'redemption_rate' => 'decimal:4',
        'min_redemption_points' => 'integer',
        'expiry_months' => 'integer',
        'bronze_threshold' => 'decimal:2',
        'silver_threshold' => 'decimal:2',
        'gold_threshold' => 'decimal:2',
        'bronze_discount_pct' => 'decimal:2',
        'silver_discount_pct' => 'decimal:2',
        'gold_discount_pct' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * The active loyalty configuration for a branch, creating it from the
     * column defaults on first use so the program runs out of the box.
     */
    public static function forBranch(int $branchId): self
    {
        return static::firstOrCreate(['branch_id' => $branchId]);
    }

    /**
     * الفئة التي يستحقها إنفاقٌ تراكميّ ما عند حدود هذا الفرع.
     *
     * الاشتقاق الوحيد للفئة في النظام كلّه — يقرأه الاكتساب وكلا مساري السحب
     * وأمرُ إعادة الاحتساب، فلا تتفرّق القاعدة على أربعة مواضع.
     *
     * بلوغ الحدّ يكفي (`>=`): من أنفق 500 يبلغ حدّ 500. والإنفاق يُقاس **شاملاً
     * ضريبة القيمة المضافة** كما يقرؤه العميل على فاتورته — بخلاف النقاط التي
     * تُحتسب صافيةً من الضريبة.
     */
    public function tierForSpend(float $spend): CustomerTierEnum
    {
        return match (true) {
            $spend >= (float) $this->gold_threshold => CustomerTierEnum::Gold,
            $spend >= (float) $this->silver_threshold => CustomerTierEnum::Silver,
            $spend >= (float) $this->bronze_threshold => CustomerTierEnum::Bronze,
            default => CustomerTierEnum::None,
        };
    }

    /**
     * The automatic discount percentage granted to a customer of the given
     * loyalty tier. Untiered customers get nothing.
     */
    public function discountPctForTier(CustomerTierEnum $tier): float
    {
        return (float) match ($tier) {
            CustomerTierEnum::Bronze => $this->bronze_discount_pct,
            CustomerTierEnum::Silver => $this->silver_discount_pct,
            CustomerTierEnum::Gold => $this->gold_discount_pct,
            CustomerTierEnum::None => 0,
        };
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
