<?php

namespace App\Http\Requests\CatalogCategory;

use App\Http\Requests\Concerns\PinsCatalogueBranch;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCatalogCategoryRequest extends FormRequest
{
    use PinsCatalogueBranch;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // A row never changes owner: the name stays unique inside the view of
        // whoever owns it (تاسك 47).
        $category = $this->route('category');

        return [
            'name_ar' => [
                'required',
                'string',
                'max:255',
                $this->uniqueInBranchView('catalog_categories', 'name_ar', $category->branch_id, $category->id),
            ],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
            'image' => ['nullable', 'image', 'max:5120'],
        ];
    }
}
