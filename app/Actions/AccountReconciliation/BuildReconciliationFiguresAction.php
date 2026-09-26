<?php

namespace App\Actions\AccountReconciliation;

use App\Http\Controllers\SalesReportController;

/**
 * تاسك 121 — أرقام النظام الحيّة لمطابقة يومٍ وفرع.
 *
 * صافي المبلغ = «الإجمالي بعد خصم مصروف النقد» (تاسك 119). التلقائي = كل طريقةٍ
 * غير الشبكة (النقد منها) ناقص مصروفات النقد — فيؤول الفرق إلى
 * «الأجهزة المُدخلة − محصَّل الشبكة في النظام».
 */
class BuildReconciliationFiguresAction
{
    public function __construct(private readonly SalesReportController $salesReport) {}

    /**
     * @return array{systemNet: float, autoBreakdown: list<array{name: string, total: float}>, autoTotal: float}
     */
    public function handle(int $branchId, string $date): array
    {
        $figures = $this->salesReport->reconciliationFigures($branchId, $date);

        $lines = collect($figures['methods'])
            ->reject(fn (array $m) => $m['isNetwork'])
            ->map(fn (array $m) => ['name' => $m['methodName'], 'total' => round($m['total'], 2)])
            ->values();

        if ($figures['cashExpenses'] != 0) {
            $lines->push(['name' => 'مصروفات النقد', 'total' => -$figures['cashExpenses']]);
        }

        return [
            'systemNet' => $figures['systemNet'],
            'autoBreakdown' => $lines->all(),
            'autoTotal' => round($lines->sum('total'), 2),
        ];
    }
}
