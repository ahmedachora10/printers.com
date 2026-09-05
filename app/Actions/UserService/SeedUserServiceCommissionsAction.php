<?php

namespace App\Actions\UserService;

use App\Enums\Roles;
use App\Models\BranchService;
use App\Models\User;
use App\Models\UserService;
use Illuminate\Support\Facades\DB;

/**
 * تاسك 85: ربطُ خدمةٍ جديدة بفرعٍ يكتب لكل موظف فيه صفَّ عمولة بقيمة عمولته
 * الأساسية (`users.base_commission_pct`).
 *
 * ⚠️ والقاعدة نفسها لم تُنقض: «لا صفَّ في `user_services` = صفر بالمئة» كما هي
 * (راجع {@see UserService} و`CalculateServiceInvoiceAction`). ما تغيّر أن الصفّ
 * يُكتب فعلاً لا أن الاحتياطي صار يقرأ العمولة الأساسية — ولو قرأها الاحتياطي
 * لكسب موظفٌ لم يُقرَّ له بخدمةٍ عمولةً عليها بأثر رجعي في كل فاتورة قديمة
 * تُعاد قراءتها. والرقم المكتوب هنا يبقى مرئياً في شاشة الموظف وقابلاً للتعديل
 * والحذف، بخلاف احتياطيٍّ خفيّ لا يظهر في أي مكان.
 */
class SeedUserServiceCommissionsAction
{
    /**
     * @return int عدد الصفوف المكتوبة
     */
    public function handle(BranchService $branchService): int
    {
        return DB::transaction(function () use ($branchService): int {
            $employees = User::query()
                ->where('branch_id', $branchService->branch_id)
                ->where('is_active', true)
                // عمولةٌ أساسية صفر لا تُكتب: الصفّ الصفري كغيابه، ولا داعي
                // لصفوفٍ بلا أثر تُثقل الشاشة.
                ->where('base_commission_pct', '>', 0)
                ->whereHas('roles', fn ($q) => $q->where('name', Roles::EMPLOYEE->value))
                ->pluck('base_commission_pct', 'id');

            if ($employees->isEmpty()) {
                return 0;
            }

            // صفٌّ موجود لا يُكتب فوقه: تعديلٌ يدويٌّ سابق لا يمحوه ربطٌ جديد.
            $existing = UserService::query()
                ->where('branch_service_id', $branchService->id)
                ->whereIn('user_id', $employees->keys())
                ->pluck('user_id');

            $rows = $employees
                ->except($existing->all())
                ->map(fn ($pct, $userId) => [
                    'user_id' => $userId,
                    'branch_service_id' => $branchService->id,
                    'commission_override_pct' => $pct,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
                ->values()
                ->all();

            if ($rows === []) {
                return 0;
            }

            // إدراجٌ جماعيٌّ واحد — لا استعلامٌ لكل موظف في فرعٍ قد يضمّ عشرات.
            UserService::query()->insert($rows);

            return count($rows);
        });
    }
}
