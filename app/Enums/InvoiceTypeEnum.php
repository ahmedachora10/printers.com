<?php

namespace App\Enums;

use App\Models\ProductInvoice;
use App\Models\ServiceInvoice;

enum InvoiceTypeEnum: string
{
    case PRODUCT = 'product';
    case SERVICE = 'service';

    /** @return class-string<ProductInvoice|ServiceInvoice> */
    public function modelClass(): string
    {
        return match ($this) {
            self::PRODUCT => ProductInvoice::class,
            self::SERVICE => ServiceInvoice::class,
        };
    }

    public function table(): string
    {
        return match ($this) {
            self::PRODUCT => 'product_invoices',
            self::SERVICE => 'service_invoices',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PRODUCT => 'فاتورة منتجات',
            self::SERVICE => 'فاتورة خدمات',
        };
    }

    public static function all(): array
    {
        return array_map(fn ($case) => $case->value, self::cases());
    }
}
