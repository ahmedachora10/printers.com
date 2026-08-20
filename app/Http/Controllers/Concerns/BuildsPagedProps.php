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
     * @return array{data: list<mixed>, meta: array<string, int>}
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

    /** @return array<string, int> */
    protected function pageMeta(int $currentPage, int $lastPage, int $total, int $perPage): array
    {
        return [
            'current_page' => $currentPage,
            'last_page' => $lastPage,
            'total' => $total,
            'per_page' => $perPage,
        ];
    }
}
