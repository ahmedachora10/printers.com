<?php

namespace App\Http\Resources\User;

use App\Enums\Roles;
use App\Models\User;
use App\Support\HtmlSanitizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $role = $this->roles->first()?->name;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'phone' => $this->phone,
            'branchId' => $this->branch_id,
            'branchName' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'role' => $role,
            'roleLabel' => $role ? Roles::tryFrom($role)?->label() : null,
            'salary' => (float) $this->salary,
            'baseCommissionPct' => (float) $this->base_commission_pct,
            'referralCommissionPct' => (float) $this->referral_commission_pct,
            'joinedDate' => $this->joined_date?->toDateString(),
            'notes' => $this->notes,
            'notesExcerpt' => Str::limit(HtmlSanitizer::toPlainText($this->notes) ?? '', 160) ?: null,
            'isActive' => $this->is_active,
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
