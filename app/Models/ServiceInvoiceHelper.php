<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

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

    /**
     * تاسك 204 — مَن تُنسب إليه مبيعات كل فاتورة خدمة، وبأيّ حصّة منها: صفٌّ
     * لكل مساعد بحصّته (share_net مُعاداً إليها الضريبة ÷ الإجمالي)، وصفٌّ لصاحب
     * الفاتورة بالباقي. مجموع الحصص في الفاتورة 1 دائماً، فجمع الصفوف بلا فلتر
     * موظف يعيد إجمالي الشركة نفسه دون تكرار.
     *
     * الأعمدة: invoice_id, user_id, factor. ‎1.0 و100.0: SQLite يقسم الأعداد
     * الصحيحة قسمةً صحيحة.
     */
    public static function salesShares(): Builder
    {
        $helpers = DB::table('service_invoice_helpers as h')
            ->join('service_invoices as i', 'i.id', '=', 'h.service_invoice_id')
            ->select([
                'h.service_invoice_id as invoice_id',
                'h.user_id',
                DB::raw('COALESCE(ROUND(h.share_net * (1 + i.vat_pct / 100.0), 2) * 1.0 / NULLIF(i.total_amount, 0), 0) as factor'),
            ]);

        $owners = DB::table('service_invoices as i')
            ->leftJoinSub(
                DB::query()->fromSub($helpers, 'hs')->groupBy('hs.invoice_id')->select('hs.invoice_id', DB::raw('SUM(hs.factor) as shared')),
                's', 's.invoice_id', '=', 'i.id',
            )
            ->select(['i.id as invoice_id', 'i.user_id', DB::raw('1 - COALESCE(s.shared, 0) as factor')]);

        return $owners->unionAll($helpers);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
