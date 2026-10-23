<?php

namespace App\Models;

use App\Enums\InvoiceStatusEnum;
use App\Enums\InvoiceTypeEnum;
use App\Enums\QuotationStatusEnum;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * عرض سعر مستقل عن الفواتير. لا يدخل مبيعاً ولا عمولة ولا نقاطاً، ويتحوّل
 * إلى فاتورة عبر مسار الحفظ العادي لنقطة البيع.
 *
 * `data` لقطة حاسبة الفاتورة: {attributes, lines, helpers, shipments, agent_ids}.
 */
class Quotation extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'quotation_number',
        'type',
        'branch_id',
        'user_id',
        'customer_id',
        'status',
        'data',
        'total_amount',
        'rejection_reason',
        'decided_at',
        'invoice_id',
        'invoice_number',
    ];

    protected $casts = [
        'type' => InvoiceTypeEnum::class,
        'status' => QuotationStatusEnum::class,
        'data' => 'array',
        'total_amount' => 'decimal:2',
        'decided_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'total_amount', 'rejection_reason', 'invoice_number'])->useLogName('sales');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** يُستدعى داخل المعاملة. */
    public static function nextNumber(int $branchId): string
    {
        $seq = self::withTrashed()->where('branch_id', $branchId)->lockForUpdate()->count() + 1;

        return sprintf('QT-%03d-%05d', $branchId, $seq);
    }

    /**
     * فاتورةٌ غير محفوظة مبنيّة من اللقطة، فتعرضها شاشتا التعديل والطباعة
     * القائمتان كما تعرضان أي فاتورة — بلا محرّرٍ ولا ورقةٍ ثانية.
     */
    public function toInvoice(): ServiceInvoice|ProductInvoice
    {
        $isService = $this->type === InvoiceTypeEnum::SERVICE;
        $invoice = $isService ? new ServiceInvoice : new ProductInvoice;
        $lineClass = $isService ? ServiceInvoiceLine::class : ProductInvoiceLine::class;

        $invoice->forceFill([
            ...$this->data['attributes'],
            'invoice_number' => $this->quotation_number,
            'branch_id' => $this->branch_id,
            'user_id' => $this->user_id,
            'status' => InvoiceStatusEnum::DUE,
            'created_at' => $this->created_at,
        ]);

        $invoice->setRelation('lines', $this->hydrate($lineClass, $this->data['lines']));
        $invoice->setRelation('customer', $this->customer);
        $invoice->setRelation('user', $this->user);
        $invoice->setRelation('branch', $this->branch);
        $invoice->setRelation('paymentMethod', null);
        $invoice->setRelation('payments', new Collection);
        $invoice->setRelation('media', new Collection);

        if ($isService) {
            $invoice->setRelation('helpers', $this->hydrate(ServiceInvoiceHelper::class, $this->data['helpers'] ?? []));
            $invoice->setRelation('shipments', $this->hydrate(ServiceInvoiceShipment::class, $this->data['shipments'] ?? []));
            $invoice->setRelation('invoiceAgents', $this->hydrate(
                ServiceInvoiceAgent::class,
                array_map(fn ($id) => ['agent_id' => $id], $this->data['agent_ids'] ?? []),
            ));
        }

        return $invoice;
    }

    /**
     * يقفل العرض ويختمه «تم تحويله» داخل معاملة إنشاء الفاتورة. لا يُحوَّل إلا
     * عرضٌ معتمد من النوع والفرع نفسيهما — فلا تحويل مرتين.
     */
    public static function markConverted(int $id, ServiceInvoice|ProductInvoice $invoice): void
    {
        $quotation = self::query()->lockForUpdate()->findOrFail($id);
        $type = $invoice instanceof ServiceInvoice ? InvoiceTypeEnum::SERVICE : InvoiceTypeEnum::PRODUCT;

        throw_if(
            $quotation->status !== QuotationStatusEnum::APPROVED
                || $quotation->type !== $type
                || $quotation->branch_id !== $invoice->branch_id,
            ValidationException::withMessages(['quotation_id' => 'عرض السعر غير معتمد من العميل أو حُوِّل إلى فاتورة من قبل.']),
        );

        $quotation->update([
            'status' => QuotationStatusEnum::CONVERTED,
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
        ]);
    }

    /**
     * @param  class-string<Model>  $class
     * @param  list<array<string, mixed>>  $rows
     */
    private function hydrate(string $class, array $rows): Collection
    {
        return new Collection(array_map(fn (array $row) => (new $class)->forceFill($row), $rows));
    }
}
