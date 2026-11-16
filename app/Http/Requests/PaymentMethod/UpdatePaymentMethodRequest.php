<?php

namespace App\Http\Requests\PaymentMethod;

/**
 * قواعد الإنشاء نفسها (تاسك 242): السوبر أدمن يغيّر الفروع، وطلبٌ بلا branch_ids لا
 * يغيّرها؛ والحصر يُرفض إن استُعملت الطريقة في فرعٍ خارج النطاق الجديد.
 */
class UpdatePaymentMethodRequest extends StorePaymentMethodRequest
{
    /** @return array<int, callable> */
    public function after(): array
    {
        return $this->narrowingCheck('فواتير أو دفعات');
    }
}
