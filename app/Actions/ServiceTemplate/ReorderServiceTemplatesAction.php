<?php

namespace App\Actions\ServiceTemplate;

use App\Models\ServiceTemplate;
use Illuminate\Support\Facades\DB;

class ReorderServiceTemplatesAction
{
    /**
     * تاسك 82: يعيد ترتيب القوالب المُرسلة داخل مواضعها من الترتيب العامّ.
     *
     * الجدول مصفَّح بخمسة عشر صفّاً، فما يصل هو ترتيب صفحةٍ واحدة لا الكلّ.
     * ولهذا لا تُكتب القيم 1..n للمُرسَل وحده — إذ يصطدم بترتيب بقية الصفحات —
     * بل تُحجز **مواضع** الصفوف المرسلة من التسلسل العامّ ثم تُملأ بالترتيب
     * الجديد، ويُعاد ترقيم الجدول كلّه تسلسلاً متّصلاً. وهذا وحده ما ينظّف
     * الأصفار المتساوية التي يبدأ بها كل قالب جديد.
     *
     * @param  list<int>  $ids  معرّفات الصفوف بترتيبها الجديد
     */
    public function handle(array $ids): void
    {
        DB::transaction(function () use ($ids) {
            $all = ServiceTemplate::query()->ordered()->pluck('id')->all();

            // مواضع الصفوف المرسلة في التسلسل العامّ — تُملأ بالترتيب الجديد.
            $slots = [];
            foreach ($all as $position => $id) {
                if (in_array($id, $ids, true)) {
                    $slots[] = $position;
                }
            }

            foreach ($slots as $index => $position) {
                $all[$position] = $ids[$index];
            }

            foreach ($all as $position => $id) {
                ServiceTemplate::query()->whereKey($id)->update(['sort_order' => $position + 1]);
            }
        });
    }
}
