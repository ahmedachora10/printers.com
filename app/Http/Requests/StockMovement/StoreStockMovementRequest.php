<?php

namespace App\Http\Requests\StockMovement;

use App\Enums\StockMovementTypeEnum;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class StoreStockMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            // Only the manual movement types may be recorded here; sale_out /
            // purchase_in / return_in are written by the system (POS, POs, refunds).
            'type' => ['required', 'string', (new Enum(StockMovementTypeEnum::class))->only([
                StockMovementTypeEnum::OPENING_STOCK,
                StockMovementTypeEnum::ADJUSTMENT_IN,
                StockMovementTypeEnum::ADJUSTMENT_OUT,
            ])],
            'qty' => ['required', 'integer', 'min:1'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Guard against driving stock negative on an outbound adjustment.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('type') !== StockMovementTypeEnum::ADJUSTMENT_OUT->value) {
                return;
            }

            $product = Product::find($this->input('product_id'));

            if ($product && (int) $this->input('qty') > $product->current_stock) {
                $validator->errors()->add(
                    'qty',
                    "الكمية المطلوب خصمها ({$this->input('qty')}) تتجاوز المخزون الحالي ({$product->current_stock}).",
                );
            }
        });
    }
}
