<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PaymentMethod extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
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
}
