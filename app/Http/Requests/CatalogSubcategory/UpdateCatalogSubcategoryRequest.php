<?php

namespace App\Http\Requests\CatalogSubcategory;

use App\Http\Requests\Concerns\PinsCatalogueBranch;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCatalogSubcategoryRequest extends FormRequest
{
    use PinsCatalogueBranch;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // A row never changes owner nor parent (تاسك 47).
        $subcategory = $this->route('subcategory');

        return [
            'name_ar' => [
                'required',
                'string',
                'max:255',
                $this->uniqueInBranchView(
                    'catalog_subcategories',
                    'name_ar',
                    $subcategory->branch_id,
                    $subcategory->id,
                )->where('category_id', $subcategory->category_id),
            ],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
            'image' => ['nullable', 'image', 'max:5120'],
        ];
    }
}
