<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * شكلٌ واحد للجداول المرقَّمة التي تُمرَّر إلى صفحات Inertia: `{ data, meta }`.
 *
 * الصفحة الواحدة قد تحمل أكثر من جدول، ولكلٍّ اسمُ صفحته (page، invoicePage، …)
 * حتى لا يُرجع التنقّل في أحدها الآخرَ إلى أوّله. ومنها ما لا يقوم على مُرقِّم
 * قاعدة بيانات أصلاً — كجدولٍ يُقسَّم في الذاكرة — فيبني بياناته بنفسه ويستعير
 * `pageMeta()` وحدها، ليقرأ الطرف الآخر شكلاً واحداً مهما اختلف المصدر.
 */
trait BuildsPagedProps
{
    /**
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @param  (callable(mixed): array<string, mixed>)|null  $map  تحويل كل صف إلى شكله المعروض
     * @return array{data: list<mixed>, meta: array<string, int|null>}
     */
    protected function pagedProp(LengthAwarePaginator $paginator, ?callable $map = null): array
    {
        $items = collect($paginator->items());

        return [
            'data' => ($map ? $items->map($map) : $items)->values()->all(),
            'meta' => $this->pageMeta(
                $paginator->currentPage(),
                $paginator->lastPage(),
                $paginator->total(),
                $paginator->perPage(),
            ),
        ];
    }

    /**
     * تاسك 78: `from` و`to` جزءٌ من الترويسة لا تخمينٌ في الواجهة — سطر «عرض
     * س‑ص من أصل ع» كان يُعيد حسابهما بحجم صفحةٍ مفترض فيكذب في كل شاشة لا
     * تصفّح بذلك الحجم. صفحةٌ بلا نتائج تعيد null لا صفراً.
     *
     * @return array<string, int|null>
     */
    protected function pageMeta(int $currentPage, int $lastPage, int $total, int $perPage): array
    {
        $from = $total === 0 ? null : ($currentPage - 1) * $perPage + 1;

        return [
            'current_page' => $currentPage,
            'last_page' => $lastPage,
            'total' => $total,
            'per_page' => $perPage,
            'from' => $from,
            'to' => $from === null ? null : min($currentPage * $perPage, $total),
        ];
    }
}
