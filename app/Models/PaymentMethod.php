<?php

namespace App\Models;

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

    protected $fillable = [
        'name',
        'is_active',
        'requires_attachment',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'requires_attachment' => 'boolean',
    ];

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
