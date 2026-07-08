<?php

namespace App\Http\Requests\StockReconciliation;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStockReconciliationCountsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'counts' => ['required', 'array', 'min:1'],
            'counts.*.line_id' => ['required', 'integer', 'exists:stock_reconciliation_lines,id'],
            'counts.*.physical_qty' => ['required', 'integer', 'min:0'],
        ];
    }
}
