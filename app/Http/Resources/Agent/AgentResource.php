<?php

namespace App\Http\Resources\Agent;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class AgentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $profile = $this->agentProfile;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'phone' => $this->phone,
            'branchId' => $this->branch_id,
            'branchName' => $this->branch?->name,
            'isActive' => $this->is_active,
            'agentType' => $profile?->agent_type ? [
                'value' => $profile->agent_type->value,
                'label' => $profile->agent_type->label(),
            ] : null,
            'discountMode' => $profile?->discount_mode ? [
                'value' => $profile->discount_mode->value,
                'label' => $profile->discount_mode->label(),
            ] : null,
            'discountType' => $profile?->discount_type ? [
                'value' => $profile->discount_type->value,
                'label' => $profile->discount_type->label(),
            ] : null,
            'rate' => (float) ($profile?->rate ?? 0),
            // The branches this agent works with, each on its own terms. This —
            // not branchId — is what decides where the agent may be invoiced;
            // branchId only names the primary branch.
            'branches' => $this->whenLoaded('agentBranches', fn () => $this->agentBranches
                ->map(fn (Branch $branch) => [
                    'branchId' => $branch->id,
                    'branchName' => $branch->name,
                    'discountMode' => $branch->pivot->discount_mode?->value,
                    'discountType' => $branch->pivot->discount_type?->value,
                    'rate' => (float) $branch->pivot->rate,
                ])
                ->values(), []),
            'commercialRegNo' => $profile?->commercial_reg_no,
            'createdAt' => $this->created_at?->format('d/m/Y'),
        ];
    }
}
