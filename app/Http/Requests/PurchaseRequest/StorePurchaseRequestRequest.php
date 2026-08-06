<?php

namespace App\Http\Requests\PurchaseRequest;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class StorePurchaseRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $user = Auth::user();

        // Non super-admins always raise the request for their own branch;
        // only super-admin picks the branch from the form.
        if (! $user->roleName?->isSuperAdmin()) {
            $this->merge(['branch_id' => $user->branchId]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            // A free-text item must name itself; a catalogued one takes the
            // product's name in the action.
            'lines.*.item_name' => ['required_without:lines.*.product_id', 'nullable', 'string', 'max:255'],
            'lines.*.qty' => ['required', 'integer', 'min:1'],
            'lines.*.estimated_unit_cost' => ['nullable', 'numeric', 'min:0'],
            'lines.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'lines' => 'الأصناف',
            'lines.*.item_name' => 'اسم الصنف',
            'lines.*.qty' => 'الكمية',
            'lines.*.estimated_unit_cost' => 'السعر التقديري',
        ];
    }
}
