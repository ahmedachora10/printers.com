<?php

namespace App\Enums;

/**
 * تاسك 110 — من أين دُفع المصروف. النقدي وحده يُطرح من «المتبقي من النقد» في
 * تقرير المبيعات؛ التحويل مصروفٌ فعلي للشركة لا يمسّ درج الكاشير.
 *
 * ليس `payment_method_id`: طرق الدفع تحصيلٌ من العميل، والعميل حدّد خيارين.
 */
enum ExpenseSourceEnum: string
{
    case CashDrawer = 'cash_drawer';
    case CompanyTransfer = 'company_transfer';

    public function label(): string
    {
        return match ($this) {
            self::CashDrawer => 'نقد من الكاشير',
            self::CompanyTransfer => 'تحويل بنكي من حساب الشركة',
        };
    }
}
