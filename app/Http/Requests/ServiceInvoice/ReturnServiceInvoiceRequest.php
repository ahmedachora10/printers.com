<?php

namespace App\Http\Requests\ServiceInvoice;

use App\Models\ServiceInvoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReturnServiceInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The reason is optional — the action falls back to a default one naming the
     * invoice, so a return is never blocked on wording.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:1000'],
            // تاسك 131: الاسترجاع يكتب مرتجعاً متى حُصِّل من الفاتورة شيء، فيحتاج
            // طريقة ردّه كمرتجع المحاسب — ولا شيء يُردّ من فاتورةٍ لم يُحصَّل منها.
            'payment_method_id' => $this->hasSomethingToRefund()
                ? ['required', 'integer', Rule::in($this->invoice()->branch?->enabledPaymentMethods()->pluck('id')->all() ?? [])]
                : ['nullable'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.max' => 'سبب الاسترجاع طويل جداً.',
            'payment_method_id.required' => 'طريقة ردّ المبلغ مطلوبة.',
            'payment_method_id.in' => 'طريقة الردّ غير متاحة لفرع الفاتورة.',
        ];
    }

    private function invoice(): ServiceInvoice
    {
        return $this->route('invoice');
    }

    /** نفس سقف ReturnServiceInvoiceAction::refundableCollected. */
    private function hasSomethingToRefund(): bool
    {
        $refunded = (float) $this->invoice()->refunds()->sum('amount');

        return round($this->invoice()->paidAmount() - $refunded, 2) > 0;
    }
}
