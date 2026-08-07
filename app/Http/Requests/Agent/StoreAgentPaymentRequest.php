<?php

namespace App\Http\Requests\Agent;

use App\Models\Agent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class StoreAgentPaymentRequest extends FormRequest
{
    /**
     * Authorize before validating, so an actor with no business with this agent
     * gets a 403 rather than a validation error about the branch link.
     */
    public function authorize(): bool
    {
        $agent = Agent::find($this->integer('agent_id'));

        // No agent yet: let the rules report the bad id instead of a blank 403.
        return $agent === null || Gate::allows('pay', $agent);
    }

    protected function prepareForValidation(): void
    {
        $user = Auth::user();

        // An agent may work with several branches, each settled on its own. Only
        // a super-admin picks which one; everyone else settles their own branch.
        if (! $user->roleName?->isSuperAdmin()) {
            $this->merge(['branch_id' => $user->branchId]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'agent_id' => ['required', 'integer', 'exists:users,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            // Settling a branch the agent does not work with would always produce
            // an empty run; reject it as the mis-selection it is.
            $linked = Agent::query()
                ->whereKey($this->integer('agent_id'))
                ->whereHas('agentBranches', fn ($q) => $q->where('branches.id', $this->integer('branch_id')))
                ->exists();

            if (! $linked) {
                $validator->errors()->add('branch_id', 'المندوب غير مرتبط بهذا الفرع.');
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'period_end.after_or_equal' => 'تاريخ نهاية الفترة يجب أن يكون بعد تاريخ البداية.',
            'branch_id.required' => 'يجب تحديد الفرع الذي تُسوّى عمولاته.',
        ];
    }
}
