<?php

namespace App\Actions\BranchService;

use App\Models\BranchService;
use App\Models\ServiceTemplate;
use Illuminate\Support\Facades\DB;

class AttachBranchServiceAction
{
    public function handle(array $data): BranchService
    {
        return DB::transaction(function () use ($data): BranchService {
            $serviceTemplate = ServiceTemplate::findOrFail($data['service_template_id']);

            $serviceTemplate->branches()->attach($data['branch_id'], [
                'base_commission_pct' => $data['base_commission_pct'],
                'max_discount_pct'    => $data['max_discount_pct'],
                'is_tahazir'          => $data['is_tahazir'] ?? false,
                'is_active'           => $data['is_active'] ?? true,
            ]);

            return BranchService::where('service_template_id', $data['service_template_id'])
                ->where('branch_id', $data['branch_id'])
                ->firstOrFail();
        });
    }
}
