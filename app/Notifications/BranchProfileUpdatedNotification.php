<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Tells super-admins that a branch-admin changed data on their own branch that
 * shows up on printed tax invoices or moves invoice totals.
 */
class BranchProfileUpdatedNotification extends Notification
{
    /** @var array<string, string> */
    private const LABELS = [
        'name' => 'اسم الفرع',
        'commercial_reg_no' => 'السجل التجاري',
        'tax_number' => 'الرقم الضريبي',
        'vat_rate_override' => 'نسبة الضريبة',
    ];

    /** @param  array<int, string>  $changedFields */
    public function __construct(
        private readonly int $branchId,
        private readonly string $branchName,
        private readonly string $editorName,
        private readonly array $changedFields,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $labels = collect($this->changedFields)
            ->map(fn (string $field) => self::LABELS[$field] ?? $field)
            ->implode('، ');

        return [
            'type' => 'branch_profile_updated',
            'title' => 'تعديل بيانات فرع',
            'body' => "عدّل {$this->editorName} بيانات فرع {$this->branchName}: {$labels}.",
            'url' => route('branches.index'),
            'icon' => 'Building2',
            'branchId' => $this->branchId,
        ];
    }
}
