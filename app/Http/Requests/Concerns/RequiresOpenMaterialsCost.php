<?php

namespace App\Http\Requests\Concerns;

use App\Actions\ServiceInvoice\CalculateServiceInvoiceAction;
use App\Models\BranchService;

/**
 * تاسك 77: خدمةٌ «لها خامات» وتكلفتها صفر تعني أن التكلفة تُحدَّد وقت البيع،
 * فيكتبها الموظف في سطر الفاتورة.
 *
 * والرقم مُلزِمٌ موجبٌ لا اختياري: تكلفة الخامة أرضيةُ سعر البيع للموظف
 * (تاسك 65) وتُخصم من أساس عمولته (تاسك 54)، فتصفيرها تُسقط أرضيته وتكبّر
 * عمولته معاً — وهو الاتجاه الوحيد الخطر. أما رفعها فضررُه عليه وحده.
 *
 * القواعد تُبنى لكل سطر بمعرّفه لا بـ`lines.*`: الشرط يخصّ الخدمة المختارة في
 * ذلك السطر بعينه.
 */
trait RequiresOpenMaterialsCost
{
    /** @return array<string, mixed> */
    protected function openMaterialsCostRules(): array
    {
        // من يملك تعديل الخامات (المحاسب ومدير الفرع) لا يلزمه هذا: له أن يكتب
        // صفراً عن قصد، والمنع الذي تُفتح فيه الثغرة ليس عليه أصلاً.
        if (! $this->user()?->roleName?->isEmployee()) {
            return [];
        }

        $lines = $this->input('lines');

        if (! is_array($lines)) {
            return [];
        }

        $serviceIds = collect($lines)->pluck('branch_service_id')->filter()->unique();

        $openIds = BranchService::query()
            ->whereKey($serviceIds)
            ->get(['id', 'has_materials', 'materials_cost'])
            ->filter(fn (BranchService $s) => CalculateServiceInvoiceAction::materialsCostIsOpen($s))
            ->pluck('id')
            ->flip();

        $rules = [];

        foreach ($lines as $index => $line) {
            if ($openIds->has((int) ($line['branch_service_id'] ?? 0))) {
                $rules["lines.{$index}.materials_cost"] = ['required', 'numeric', 'min:0.01'];
            }
        }

        return $rules;
    }

    /** @return array<string, string> */
    protected function openMaterialsCostMessages(): array
    {
        $messages = [];

        foreach (array_keys($this->openMaterialsCostRules()) as $key) {
            $messages["{$key}.required"] = 'أدخل تكلفة الخامات لهذه الخدمة.';
            $messages["{$key}.min"] = 'أدخل تكلفة الخامات لهذه الخدمة.';
        }

        return $messages;
    }
}
