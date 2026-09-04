<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * نموذج استيراد خدمات الفرع: ورقتا التصدير نفسهما فارغتين، وفي كلٍّ صفُّ مثال.
 *
 * العناوين تُطلب من BranchServicesExport لا تُكتب هنا، فنموذجٌ يخالف التصدير فخّ.
 */
class BranchServicesTemplateExport implements WithMultipleSheets
{
    /** @return array<int, object> */
    public function sheets(): array
    {
        return [
            new ImportTemplateExport(
                BranchServicesExport::serviceHeadings(),
                [['طباعة ملونة A4', '10.00', '15.00', '', '', 'بالوحدة', '0.00', '0.00', 0, 0, '0.00', 'وجه واحد | وجهين', 1]],
                BranchServicesExport::SERVICES_SHEET,
            ),
            new ImportTemplateExport(
                BranchServicesExport::commissionHeadings(),
                [['طباعة ملونة A4', 'أحمد علي', 'ahmed', '12.00']],
                BranchServicesExport::COMMISSIONS_SHEET,
            ),
        ];
    }
}
