<?php

namespace App\Http\Requests\AccountReconciliation;

use App\Models\AccountReconciliation;
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
            // طريقة شبكة يراها الفرع (عامةً أو ملكه) — عدة أجهزة لنفس الطريقة مسموحة.
            'devices.*.payment_method_id' => ['required', 'integer', Rule::exists('payment_methods', 'id')
                ->where('is_network', true)
                ->whereNull('deleted_at')
                ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $this->branchId()))],
            'devices.*.device_label' => ['required', 'string', 'max:100'],
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
