<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserAttachmentRequest extends FormRequest
{
    /** الصلاحية تُحسم بالسياسة في الكنترولر، لا هنا. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1', 'max:10'],
            'files.*' => [
                'required',
                'file',
                'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx,ppt,pptx',
                'max:10240',
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'files.required' => 'اختر ملفاً واحداً على الأقل.',
            'files.max' => 'لا يمكن رفع أكثر من 10 ملفات دفعةً واحدة.',
            'files.*.mimes' => 'الملفات المسموحة: PDF أو صورة (jpg, png, webp) أو مستند Office.',
            'files.*.max' => 'حجم الملف يجب ألا يتجاوز 10 ميجابايت.',
        ];
    }
}
