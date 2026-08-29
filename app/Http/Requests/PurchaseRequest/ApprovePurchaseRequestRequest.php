<?php

namespace App\Http\Requests\PurchaseRequest;

use App\Models\PurchaseRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * تاسك 68: approving now feeds the branch's stock, so the approver settles two
 * things the requester could leave open — which inventory product each line
 * really is, and what it actually costs. Both are required: a stock movement
 * needs a product to be written against, and its unit cost is what the
 * immutable ledger records.
 */
class ApprovePurchaseRequestRequest extends FormRequest
{
    /**
     * Checked here rather than only in the controller: a FormRequest validates
     * after it authorizes, and someone who may not decide should be refused
     * outright, not told which line is missing a price.
     */
    public function authorize(): bool
    {
        return Gate::allows('decide', $this->route('purchase_request'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var PurchaseRequest $purchaseRequest */
        // The route parameter is snake_cased: {purchase_request}.
        $purchaseRequest = $this->route('purchase_request');

        return [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.id' => [
                'required',
                'integer',
                // A line can only be settled on the request being approved.
                Rule::exists('purchase_request_lines', 'id')
                    ->where('request_id', $purchaseRequest->id),
            ],
            'lines.*.product_id' => [
                'required',
                'integer',
                // Stock is fed per branch: a line may only point at a product
                // of the branch that raised the request.
                Rule::exists('products', 'id')
                    ->where('branch_id', $purchaseRequest->branch_id)
                    ->whereNull('deleted_at'),
            ],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'lines.required' => 'لا يمكن اعتماد طلب بلا أصناف.',
            'lines.*.product_id.required' => 'اربط كل صنف بمنتج في المخزون قبل الاعتماد.',
            'lines.*.product_id.exists' => 'المنتج المختار غير موجود في فرع الطلب.',
            'lines.*.unit_cost.required' => 'اكتب تكلفة الوحدة لكل صنف قبل الاعتماد.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'lines' => 'الأصناف',
            'lines.*.product_id' => 'المنتج',
            'lines.*.unit_cost' => 'تكلفة الوحدة',
        ];
    }

    /**
     * The validated lines keyed by line id, so the action can settle each line
     * without re-searching the payload.
     *
     * @return array<int, array{product_id: int, unit_cost: float}>
     */
    public function linesById(): array
    {
        $lines = [];

        foreach ($this->validated()['lines'] as $line) {
            $lines[(int) $line['id']] = [
                'product_id' => (int) $line['product_id'],
                'unit_cost' => (float) $line['unit_cost'],
            ];
        }

        return $lines;
    }
}
