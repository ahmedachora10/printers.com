<?php

namespace App\Actions\ServiceTemplate;

use App\Models\ServiceTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteServiceTemplateAction
{
    public function handle(ServiceTemplate $serviceTemplate): void
    {
        if ($serviceTemplate->branches()->exists()) {
            throw ValidationException::withMessages([
                'service_template' => 'لا يمكن حذف قالب الخدمة لأنه مرتبط بفروع.',
            ]);
        }

        DB::transaction(fn (): bool => $serviceTemplate->delete());
    }
}
