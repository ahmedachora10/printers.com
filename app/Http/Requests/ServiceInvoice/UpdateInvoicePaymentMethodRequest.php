<?php

namespace App\Http\Requests\ServiceInvoice;

use App\Enums\InvoiceTypeEnum;
use App\Models\Branch;
use App\Models\InvoicePayment;
use App\Models\PaymentMethod;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * تعديل طريقة الدفع — على الفاتورة (قبل الاعتماد أو بعده، تاسك 99) أو على صفّ
 * دفعةٍ بعينها (عربون أو دفعة لاحقة).
 *
 * الهدف يُقرأ من المسار: `{invoice}` (مسار فواتير الخدمات القديم في شاشة
 * المراجعة)، أو `{type}/{id}`، أو `{payment}`.
 *
 * الإيصال إلزامي هنا كما هو إلزامي في نقطة البيع: كان هذا المسار ثغرةً تُبدَّل
 * منها الطريقة إلى «تحويل بنكي» بلا إثبات، فتُعتمد الفاتورة بعدها بلا مرفق —
 * وهو أكثر مسارات المحاسب استعمالاً. ولا يُطلب متى كان الهدف يحمل إيصالاً
 * أصلاً؛ عندها يكون الرفع استبدالاً اختيارياً.
 */
class UpdateInvoicePaymentMethodRequest extends FormRequest
{
    private ServiceInvoice|ProductInvoice|InvoicePayment|null $resolvedTarget = null;

    public function authorize(): bool
    {
        return true;
    }

    /** الفاتورة أو صفّ الدفعة الذي تتغيّر طريقته. */
    public function target(): ServiceInvoice|ProductInvoice|InvoicePayment
    {
        if ($this->resolvedTarget !== null) {
            return $this->resolvedTarget;
        }

        $target = $this->route('payment') ?? $this->route('invoice');

        if ($target === null) {
            $type = InvoiceTypeEnum::tryFrom((string) $this->route('type'));
            abort_if($type === null, 404);
            $target = $type->modelClass()::findOrFail((int) $this->route('id'));
        }

        return $this->resolvedTarget = $target;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $branch = Branch::find($this->target()->branch_id);
        $enabledIds = $branch
            ? $branch->enabledPaymentMethods()->pluck('id')->all()
            : [];

        return [
            'payment_method_id' => ['required', 'integer', Rule::in($enabledIds)],
            'receipt' => [
                $this->receiptRequired() ? 'required' : 'nullable',
                'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120',
            ],
        ];
    }

    /**
     * فاتورةٌ عليها دفعات تُقرأ طريقتها من صفوفها (تقرير المبيعات يقدّم طريقة
     * الدفعة على طريقة الفاتورة)، فتعديل طريقة الفاتورة عندها لا يغيّر شيئاً
     * ممّا يراه أحد — يُوجَّه المستخدم إلى صفّ الدفعة بدل أن ينجح صامتاً.
     *
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $target = $this->target();

                if (! $target instanceof InvoicePayment && $target->payments()->exists()) {
                    $validator->errors()->add(
                        'payment_method_id',
                        'هذه الفاتورة سُدّدت بدفعات — عدّل طريقة الدفعة نفسها من قائمة «الدفعات».',
                    );
                }
            },
        ];
    }

    /**
     * هل تُلزِم الطريقةُ المختارة بإيصالٍ لم يحمله الهدف بعد؟
     */
    private function receiptRequired(): bool
    {
        $id = $this->input('payment_method_id');
        $requires = $id !== null && (bool) PaymentMethod::find($id)?->requires_attachment;

        return $requires && $this->target()->receipt() === null;
    }

    public function messages(): array
    {
        return [
            'payment_method_id.required' => 'طريقة الدفع مطلوبة.',
            'payment_method_id.in' => 'طريقة الدفع غير متاحة لهذا الفرع.',
            'receipt.required' => 'يجب إرفاق إيصال التحويل لطريقة الدفع المحددة.',
            'receipt.mimes' => 'يجب أن يكون الإيصال صورة (jpg, png, webp) أو ملف PDF.',
            'receipt.max' => 'حجم الإيصال يجب ألا يتجاوز 5 ميجابايت.',
        ];
    }
}
