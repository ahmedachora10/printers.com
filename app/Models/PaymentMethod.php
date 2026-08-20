<?php

namespace App\Models;

use Database\Factories\PaymentMethodFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PaymentMethod extends Model
{
    /** @use HasFactory<PaymentMethodFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'branch_id',
        'name',
        'is_active',
        'requires_attachment',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'requires_attachment' => 'boolean',
    ];

    /** الفرع المالك — null = طريقة عامة يراها كل فرع (تاسك 59). */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * ما يراه فرعٌ بعينه: الطرق العامة + ما أضافه هو. طريقة فرعٍ آخر لا تظهر
     * ولا تُختار — وإلا تسرّبت أسماء فروع بعضها إلى بعض في منتقي نقطة البيع.
     *
     * `$branchId === null` (السوبر أدمن) يعني الكل بلا تقييد.
     *
     * @param  Builder<PaymentMethod>  $query
     */
    public function scopeVisibleToBranch($query, ?int $branchId)
    {
        if ($branchId === null) {
            return $query;
        }

        return $query->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId));
    }

    public function isReferencedByInvoices(): bool
    {
        foreach (['service_invoices', 'product_invoices'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->where('payment_method_id', $this->id)->exists()) {
                return true;
            }
        }

        return false;
    }

    public static function transferMethod(): ?PaymentMethod
    {
        return self::firstWhere('name', 'تحويل بنكي');
    }
}
