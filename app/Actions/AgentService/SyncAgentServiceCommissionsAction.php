<?php

namespace App\Actions\AgentService;

use App\Models\AgentService;
use Illuminate\Support\Facades\DB;

class SyncAgentServiceCommissionsAction
{
    /**
     * تاسك 153 — يكتب (أو يمحو) عمولة مندوب على خدمة فرع. النوع null يحذف
     * الصفّ فيعود الموظف يكتب العمولة يدوياً في نقطة البيع. كل صفّ يحمل
     * المعرّفين، فيخدم نافذة الخدمة ونافذة المندوب معاً.
     *
     * @param  list<array{agent_id: int, branch_service_id: int, commission_type: ?string, commission_value: ?float}>  $rows
     */
    public function handle(array $rows): void
    {
        DB::transaction(function () use ($rows): void {
            foreach ($rows as $row) {
                $key = ['agent_id' => $row['agent_id'], 'branch_service_id' => $row['branch_service_id']];

                // مفتاحٌ غائب كقيمةٍ فارغة: الطلب يقبل النوع nullable.
                if (($row['commission_type'] ?? null) === null) {
                    AgentService::query()->where($key)->delete();

                    continue;
                }

                AgentService::query()->updateOrCreate($key, [
                    'commission_type' => $row['commission_type'],
                    'commission_value' => $row['commission_value'],
                ]);
            }
        });
    }
}
