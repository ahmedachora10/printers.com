<?php

namespace App\Imports;

use App\Imports\Sheets\BranchServiceCommissionsSheetImport;
use App\Imports\Sheets\BranchServicesSheetImport;
use App\Support\Import\ImportReport;
use Maatwebsite\Excel\Concerns\SkipsUnknownSheets;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * خدمات فرعٍ واحد من الورقتين اللتين يكتبهما BranchServicesExport.
 *
 * إضافة وتحديث فقط — لا حذف: خدمةٌ اختفت من الملف تبقى مرتبطة بالفرع، فلها سطور
 * فواتير وعمولات مصروفة. ومن أراد فكّها فكّها من الشاشة.
 *
 * الورقتان تتشاركان تقريراً واحداً كي يرى المستخدم حصيلة الملف كلّه في نافذةٍ
 * واحدة؛ والترتيب مقصود: ورقة الخدمات أولاً كي تجد ورقةُ العمولات الخدمةَ التي
 * أنشأها الملف نفسه قبل سطرٍ واحد.
 *
 * الأرقام مفاتيح لا أسماء: الورقتان تُطابَقان بالفهرس لا باسم الورقة، فمستخدمٌ
 * أعاد تسمية أوراقه لا يفقد استيراده. وSkipsUnknownSheets تجعل ملفاً بورقةٍ
 * واحدة (ورقة الخدمات وحدها) يمرّ بدل أن يُرفض.
 */
class BranchServicesImport implements SkipsUnknownSheets, WithMultipleSheets
{
    public readonly ImportReport $report;

    public function __construct(private readonly int $branchId, bool $dryRun = false)
    {
        $this->report = (new ImportReport($dryRun))
            ->declareCounter('servicesCreated', 'خدمات جديدة', 'success')
            ->declareCounter('servicesUpdated', 'خدمات محدَّثة', 'info')
            ->declareCounter('templatesCreated', 'قوالب خدمات أُنشئت', 'success')
            ->declareCounter('commissionsSet', 'عمولات موظفين محدَّثة', 'info')
            ->declareCounter('commissionsCleared', 'عمولات أُزيلت', 'warning')
            ->declareCounter('skipped', 'صفوف متجاهَلة', 'warning');
    }

    /** @return array<int, object> */
    public function sheets(): array
    {
        return [
            0 => new BranchServicesSheetImport($this->branchId, $this->report),
            1 => new BranchServiceCommissionsSheetImport($this->branchId, $this->report),
        ];
    }

    /** ورقةٌ لم يحملها الملف: لا شيء يُستورد منها، ولا خطأ يُرفع بسببها. */
    public function onUnknownSheet($sheetName): void {}
}
