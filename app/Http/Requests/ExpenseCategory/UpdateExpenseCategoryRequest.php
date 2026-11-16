<?php

namespace App\Http\Requests\ExpenseCategory;

/**
 * قواعد الإنشاء نفسها. تاسك 231/238: السوبر أدمن يغيّر الفروع عند التعديل (فارغ = عامة)،
 * وطلبٌ بلا branch_ids لا يغيّرها؛ غيره يبقى على فرعه. والحصر يُرفض إن كانت للفئة
 * مصروفات في فرعٍ خارج النطاق الجديد.
 */
class UpdateExpenseCategoryRequest extends StoreExpenseCategoryRequest
{
    /** @return array<int, callable> */
    public function after(): array
    {
        return $this->narrowingCheck('مصروفات');
    }
}
