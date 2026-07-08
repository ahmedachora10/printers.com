<?php

namespace App\Http\Requests\StockReconciliation;

use Illuminate\Foundation\Http\FormRequest;

class StoreStockReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Only consulted for super-admins — branch users always count their own branch.
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
