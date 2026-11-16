<?php

namespace App\Http\Requests\ProductCategory;

/**
 * قواعد الإنشاء نفسها؛ طلبٌ بلا branch_ids لا يغيّر النطاق، والحصر يُرفض إن كانت للفئة
 * منتجات في فرعٍ خارج النطاق الجديد (تاسك 237).
 */
class UpdateProductCategoryRequest extends StoreProductCategoryRequest
{
    /** @return array<int, callable> */
    public function after(): array
    {
        return $this->narrowingCheck('منتجات');
    }
}
