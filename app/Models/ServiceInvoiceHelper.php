<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * موظفٌ ساعد في فاتورة خدمة. صفّ عمولته في commission_ledger يشير إليه بـ
 * invoice_line_type = هذا الصنف، كما يشير صفّ صاحب الفاتورة إلى سطرها.
 */
class ServiceInvoiceHelper extends Model
{
    protected $fillable = [
        'service_invoice_id',
        'user_id',
        'branch_service_id',
        'service_name',
        'share_type',
        'share_value',
        'share_net',
        'commission_pct',
        'commission_amount',
        'is_tahazir',
    ];

    protected $casts = [
        'share_value' => 'decimal:2',
        'share_net' => 'decimal:2',
        'commission_pct' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'is_tahazir' => 'boolean',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
