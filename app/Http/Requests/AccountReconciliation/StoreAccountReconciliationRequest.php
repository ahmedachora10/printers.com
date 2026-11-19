<?php

namespace App\Http\Requests\AccountReconciliation;

use App\Models\AccountReconciliation;
use App\Models\CardType;
use App\Rules\VisibleToBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** تاسك 121 — موازنات أجهزة الشبكة ليومٍ وفرع: الإدخال اليدوي الوحيد في المطابقة. */
class StoreAccountReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', AccountReconciliation::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isSuper = $this->user()->roleName->isSuperAdmin();

        return [
            'branch' => [Rule::excludeIf(! $isSuper), 'required', 'integer', 'exists:branches,id'],
            'date' => ['required', 'date_format:Y-m-d'],
            'devices' => ['present', 'array'],
            // تاسك 146: جهاز غير محذوف من فرع المطابقة + نوع بطاقة غير محذوف؛ طريقة الدفع تُشتقّ من الجهاز.
            'devices.*.network_device_id' => ['required', 'integer', Rule::exists('network_devices', 'id')
                ->where('branch_id', $this->branchId())
                ->whereNull('deleted_at')],
            // تاسك 243: نوعٌ يراه فرع المطابقة.
            'devices.*.card_type_id' => ['required', 'integer', new VisibleToBranch(CardType::class, $this->branchId() ?? 0)],
            'devices.*.amount' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** غير السوبر أدمن مثبَّتٌ على فرعه مهما أرسل. */
    public function branchId(): ?int
    {
        return $this->user()->roleName->isSuperAdmin() ? $this->integer('branch') ?: null : $this->user()->branchId;
    }
}
