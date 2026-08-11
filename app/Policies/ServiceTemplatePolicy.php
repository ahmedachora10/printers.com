<?php

namespace App\Policies;

use App\Models\ServiceTemplate;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ServiceTemplatePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ServiceTemplate $serviceTemplate): bool
    {
        return true;
    }

    /**
     * تاسك 45: مدير الفرع ينشئ خدماته بنفسه دون الرجوع للأدمن — والكونترولر هو
     * من يُلزم القالب الجديد بفرعه، فلا يستطيع إنشاء خدمة عامة.
     */
    public function create(User $user): bool
    {
        return $user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin();
    }

    /** مدير الفرع يعدّل ما أنشأه فرعه فقط؛ الخدمات العامة للسوبر أدمن وحده. */
    public function update(User $user, ServiceTemplate $serviceTemplate): bool
    {
        return $user->roleName->isSuperAdmin() || $this->ownsTemplate($user, $serviceTemplate);
    }

    public function delete(User $user, ServiceTemplate $serviceTemplate): bool
    {
        return $user->roleName->isSuperAdmin() || $this->ownsTemplate($user, $serviceTemplate);
    }

    /** خدمة أنشأها مدير هذا الفرع بعينه — لا خدمة عامة ولا خدمة فرع آخر. */
    private function ownsTemplate(User $user, ServiceTemplate $serviceTemplate): bool
    {
        return $user->roleName?->isBranchAdmin() === true
            && $serviceTemplate->branch_id !== null
            && $serviceTemplate->branch_id === $user->branchId;
    }

    public function restore(User $user, ServiceTemplate $serviceTemplate): bool
    {
        return $user->roleName->isSuperAdmin();
    }

    public function forceDelete(User $user, ServiceTemplate $serviceTemplate): bool
    {
        return $user->roleName->isSuperAdmin();
    }
}
