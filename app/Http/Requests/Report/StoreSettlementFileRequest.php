<?php

namespace App\Http\Requests\Report;

use Illuminate\Foundation\Http\FormRequest;

/** تاسك 122 — ملف موازنة الشبكة ليومٍ وفرع. */
class StoreSettlementFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // غير السوبر أدمن يُثبَّت على فرعه في المتحكّم مهما أرسل.
            'branch' => ['nullable', 'integer', 'exists:branches,id'],
            'date' => ['required', 'date'],
            // ملفات البنوك بلا صيغةٍ موحّدة: مستندٌ أو صورةٌ أو جدول.
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp,xlsx,xls,csv', 'max:10240'],
        ];
    }
}
