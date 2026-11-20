<?php

namespace App\Actions\CatalogCategory;

use App\Exports\CatalogueExport;
use App\Imports\CatalogueImport;
use App\Models\CatalogCategory;
use App\Models\CatalogSubcategory;
use App\Support\Import\DryRunRollback;
use App\Support\Import\ImportReport;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Imports\HeadingRowFormatter;

/**
 * «نسخ من فرع»: one branch's own catalogue rows into another branch, as that
 * branch's independent rows. It is literally the export of the source fed to
 * the import of the target — no file in between — so it merges exactly like
 * an Excel import does: missing names are added, existing prices updated,
 * nothing deleted, and the general rows both branches inherit are reused
 * rather than forked.
 */
class CopyCatalogueAction
{
    public function handle(int $fromBranchId, int $toBranchId, bool $dryRun): ImportReport
    {
        $export = new CatalogueExport($fromBranchId);
        $keys = HeadingRowFormatter::format($export->headings());
        $rows = $export->collection()->map(fn (array $row) => collect(array_combine($keys, $row)));

        $import = new CatalogueImport($toBranchId, $dryRun);

        try {
            DB::transaction(function () use ($import, $rows, $fromBranchId, $toBranchId, $dryRun) {
                $import->collection($rows);

                if ($dryRun) {
                    throw new DryRunRollback;
                }

                $this->copyImages($fromBranchId, $toBranchId);
            });
        } catch (DryRunRollback) {
            // expected: the preview wrote nothing
        }

        return $import->report;
    }

    /** The sheet carries no images; give each new target row its source's. */
    private function copyImages(int $from, int $to): void
    {
        CatalogCategory::query()->where('branch_id', $from)->whereHas('media')->get()
            ->each(function (CatalogCategory $source) use ($to) {
                $target = CatalogCategory::query()->where('branch_id', $to)
                    ->where('name_ar', $source->name_ar)->whereDoesntHave('media')->first();

                $target && $source->getFirstMedia('image')?->copy($target, 'image');
            });

        CatalogSubcategory::query()->where('branch_id', $from)->whereHas('media')->with('category:id,name_ar')->get()
            ->each(function (CatalogSubcategory $source) use ($to) {
                $target = CatalogSubcategory::query()->where('branch_id', $to)
                    ->where('name_ar', $source->name_ar)
                    ->whereHas('category', fn ($q) => $q->where('name_ar', $source->category->name_ar))
                    ->whereDoesntHave('media')->first();

                $target && $source->getFirstMedia('image')?->copy($target, 'image');
            });
    }
}
