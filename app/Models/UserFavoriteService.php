<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * تفضيل موظفٍ لخدمة، فتُرفع أعلى قائمة نقطة البيع (تاسك 76). جدول ربطٍ بحت:
 * وجود الصفّ هو التفضيل، وحذفه إلغاؤه.
 */
class UserFavoriteService extends Model
{
    protected $fillable = [
        'user_id',
        'branch_service_id',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<BranchService, $this> */
    public function branchService(): BelongsTo
    {
        return $this->belongsTo(BranchService::class);
    }
}
