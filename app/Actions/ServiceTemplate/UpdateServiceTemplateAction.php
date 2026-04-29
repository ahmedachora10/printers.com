<?php

namespace App\Actions\ServiceTemplate;

use App\Models\ServiceTemplate;
use Illuminate\Support\Facades\DB;

class UpdateServiceTemplateAction
{
    public function handle(ServiceTemplate $serviceTemplate, array $data): ServiceTemplate
    {
        return DB::transaction(function () use ($serviceTemplate, $data): ServiceTemplate {
            $serviceTemplate->update($data);

            return $serviceTemplate->fresh();
        });
    }
}
