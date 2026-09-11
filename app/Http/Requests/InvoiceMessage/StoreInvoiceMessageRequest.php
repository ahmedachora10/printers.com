<?php

namespace App\Http\Requests\InvoiceMessage;

use App\Models\InvoiceMessage;
use App\Models\ServiceInvoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** تاسك 100 — رسالة في المحادثة الداخلية. الصلاحية (postMessage) في المتحكّم. */
class StoreInvoiceMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var ServiceInvoice $invoice */
        $invoice = $this->route('invoice');

        return [
            'body' => ['nullable', 'string', 'max:2000', 'required_without:attachments'],
            'mentions' => ['array'],
            // لا يُشار إلا إلى دورَي المراجعة ومستخدمي فرع الفاتورة.
            'mentions.*' => ['string', Rule::in(InvoiceMessage::mentionables($invoice)->pluck('token')->all())],
            'attachments' => ['array', 'max:3'],
            'attachments.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'body.required_without' => 'اكتب الرسالة أو أرفق ملفاً.',
            'body.max' => 'الرسالة يجب ألا تتجاوز 2000 حرف.',
            'mentions.*.in' => 'لا يمكن الإشارة إلى هذا المستخدم في هذه الفاتورة.',
            'attachments.max' => 'ثلاثة مرفقات على الأكثر في الرسالة الواحدة.',
            'attachments.*.mimes' => 'المرفق يجب أن يكون صورة (JPG أو PNG أو WEBP) أو ملف PDF.',
            'attachments.*.max' => 'حجم المرفق يجب ألا يتجاوز 5 ميجابايت.',
        ];
    }
}
