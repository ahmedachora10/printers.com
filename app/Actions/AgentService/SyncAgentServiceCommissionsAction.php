<?php

namespace App\Actions\AgentService;

use App\Models\AgentService;
use Illuminate\Support\Facades\DB;

class SyncAgentServiceCommissionsAction
{
    /**
     * تاسك 153 — يكتب (أو يمحو) عمولة كل مندوب على خدمة فرع. النوع null يحذف
     * الصفّ فيعود الموظف يكتب العمولة يدوياً في نقطة البيع.
     *
     * @param  list<array{agent_id: int, commission_type: ?string, commission_value: ?float}>  $rows
     */
    public function handle(int $branchServiceId, array $rows): void
    {
        DB::transaction(function () use ($branchServiceId, $rows): void {
            foreach ($rows as $row) {
                $key = ['agent_id' => $row['agent_id'], 'branch_service_id' => $branchServiceId];

                if ($row['commission_type'] === null) {
                    AgentService::query()->where($key)->delete();

                    continue;
                }

                AgentService::query()->updateOrCreate($key, [
                    'commission_type' => $row['commission_type'],
                    'commission_value' => $row['commission_value'] ?? 0,
                ]);
            }
        });
    }
}
