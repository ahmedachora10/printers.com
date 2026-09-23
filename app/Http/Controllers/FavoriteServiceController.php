<?php

namespace App\Http\Controllers;

use App\Models\BranchService;
use App\Models\UserFavoriteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class FavoriteServiceController extends Controller
{
    /**
     * تاسك 76: يبدّل تفضيل الموظف لخدمة، فترتفع أعلى قائمة نقطة البيع.
     *
     * التفضيل يخصّ **المستخدم الحالي وحده** — لا يُمرَّر `user_id` من العميل
     * إطلاقاً — والخدمة يجب أن تكون في فرعه: السوبر أدمن لا فرع له فيقتصر على
     * ما يفتحه من فروع، ومدير الفرع والموظف على فرعهما.
     */
    public function toggle(BranchService $branchService): RedirectResponse
    {
        $user = Auth::user();

        abort_unless(
            $user->roleName?->isSuperAdmin() || (int) $branchService->branch_id === $user->branchId,
            403,
            'This service belongs to another branch.',
        );

        $existing = UserFavoriteService::query()
            ->where('user_id', $user->id)
            ->where('branch_service_id', $branchService->id)
            ->first();

        // تبديلٌ لا إضافة: الضغطة الثانية تحذف الصفّ ولا تكرّره.
        $existing
            ? $existing->delete()
            : UserFavoriteService::create(['user_id' => $user->id, 'branch_service_id' => $branchService->id]);

        return back(fallback: route('pos.service.create'));
    }

    /**
     * تاسك 118: يبدّل الخدمة الافتراضية للموظف في الفاتورة السريعة — الضغطة
     * على الافتراضية نفسها تُلغيها. الحارس نفسه: خدمةٌ في فرعه، له وحده.
     */
    public function toggleQuickDefault(BranchService $branchService): RedirectResponse
    {
        $user = Auth::user();

        abort_unless((int) $branchService->branch_id === $user->branchId, 403, 'This service belongs to another branch.');

        $user->forceFill([
            'quick_service_id' => (int) $user->quick_service_id === $branchService->id ? null : $branchService->id,
        ])->save();

        return back(fallback: route('pos.service.quick'));
    }
}
