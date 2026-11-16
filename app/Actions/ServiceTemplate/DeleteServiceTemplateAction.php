<?php

namespace App\Actions\ServiceTemplate;

use App\Models\ServiceTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteServiceTemplateAction
{
    /**
     * روابط الفروع وما يتبعها (عمولات الموظفين والوكلاء، الخامات، المفضّلة) تُحذف
     * بالـcascade في قاعدة البيانات؛ المانع الوحيد أن تكون إحدى خدمات الفروع على فاتورة.
     */
    public function handle(ServiceTemplate $serviceTemplate): void
    {
        $branchServiceIds = DB::table('branch_services')->where('service_template_id', $serviceTemplate->id)->pluck('id');

        $invoiced = DB::table('service_invoice_lines')->whereIn('branch_service_id', $branchServiceIds)->exists()
            || DB::table('service_invoice_helpers')->whereIn('branch_service_id', $branchServiceIds)->exists();

        if ($invoiced) {
            throw ValidationException::withMessages([
                'service_template' => 'لا يمكن حذف قالب الخدمة لأنه مستخدم في فواتير.',
            ]);
        }

        DB::transaction(fn (): bool => $serviceTemplate->delete());
    }
}
