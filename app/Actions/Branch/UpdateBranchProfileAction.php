<?php

namespace App\Actions\Branch;

use App\Models\Branch;
use App\Models\User;
use App\Notifications\BranchProfileUpdatedNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Self-service branch edit for the branch's own manager (see
 * UpdateBranchProfileRequest for the editable subset). Wraps the plain
 * UpdateBranchAction with the audit trail and super-admin notification that the
 * super-admin-only /branches screen does not need.
 */
class UpdateBranchProfileAction
{
    /**
     * Columns whose change super-admins are told about: they either land on
     * printed tax invoices (name, registration numbers) or move invoice
     * totals (VAT rate).
     */
    private const SENSITIVE = ['name', 'commercial_reg_no', 'tax_number', 'vat_rate_override'];

    public function __construct(private readonly UpdateBranchAction $updateBranch) {}

    /** @param  array<string, mixed>  $data */
    public function handle(Branch $branch, array $data, User $editor): Branch
    {
        $changed = $this->changedFields($branch, $data);

        $branch = DB::transaction(function () use ($branch, $data, $editor, $changed) {
            $updated = $this->updateBranch->handle($branch, $data);

            activity('branches')
                ->causedBy($editor)
                ->performedOn($updated)
                ->withProperties(['changed' => $changed])
                ->log('updated own branch profile');

            return $updated;
        });

        $sensitive = array_values(array_intersect($changed, self::SENSITIVE));

        if ($sensitive !== []) {
            Notification::send($this->superAdmins($editor), new BranchProfileUpdatedNotification(
                $branch->id,
                $branch->name,
                $editor->name,
                $sensitive,
            ));
        }

        return $branch;
    }

    /**
     * Names of the columns this submission actually changes. `fill()` mutates
     * the model in memory only; the real write still happens through
     * UpdateBranchAction with the same values.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function changedFields(Branch $branch, array $data): array
    {
        $changed = array_keys($branch->fill(Arr::except($data, ['logo']))->getDirty());

        if (! empty($data['logo'])) {
            $changed[] = 'logo';
        }

        return $changed;
    }

    /** @return Collection<int, User> */
    private function superAdmins(User $editor): Collection
    {
        return User::query()
            ->whereKeyNot($editor->getKey())
            ->whereHas('roles', fn ($q) => $q->where('name', 'super-admin'))
            ->get();
    }
}
