<?php

namespace App\Models;

use App\Models\Concerns\ScopedToBranches;
use Database\Factories\PaymentMethodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PaymentMethod extends Model
{
    /** @use HasFactory<PaymentMethodFactory> */
    use HasFactory, SoftDeletes;

    /**
     * تاسك 59/242: فرعٌ أو عدّة فروع، وبلا فروع = عامة يراها كل فرع. طريقة فرعٍ آخر لا
     * تظهر ولا تُختار — وإلا تسرّبت أسماء فروع بعضها إلى بعض في منتقي نقطة البيع.
     */
    use ScopedToBranches;

    protected $fillable = [
        'name',
        'is_active',
        'requires_attachment',
        'is_cash',
        'is_network',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'requires_attachment' => 'boolean',
        'is_cash' => 'boolean',
        'is_network' => 'boolean',
    ];

    public function scopeIsNetwork($query)
    {
        return $query->where('is_network', true);
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

    /**
     * الفروع التي استعملت الطريقة: فواتيرها ودفعاتها ومرتجعاتها — فلا تُحصر خارجها.
     *
     * @return list<int>
     */
    public function usedInBranchIds(): array
    {
        $ids = collect(['service_invoices', 'product_invoices', 'invoice_payments', 'refunds'])
            ->flatMap(fn (string $table) => DB::table($table)->where('payment_method_id', $this->id)->distinct()->pluck('branch_id'));

        return $ids->unique()->map(fn ($id) => (int) $id)->values()->all();
    }

    /** هل تشترط الطريقة المختارة إيصالاً مرفقاً (تحويل بنكي…)؟ */
    public static function requiresReceipt(mixed $id): bool
    {
        return $id !== null && (bool) self::find($id)?->requires_attachment;
    }

    public static function transferMethod(): ?PaymentMethod
    {
        return self::firstWhere('name', 'تحويل بنكي');
    }
}
