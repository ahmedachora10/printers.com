import { type ServicePricingType } from '@/types/branch-service';

/**
 * لغة وحدة القياس في شاشات الخدمة، من مصدرٍ واحد (تاسك 80).
 *
 * التسعير بالوحدة يعدّ قطعاً، والتسعير المقاسيّ يقيس: مترٌ مربع ببُعدين، ومترٌ
 * طولي ببُعدٍ واحد. والرقم — السعر وتكلفة الخامة وحدّا البيع — معناه يتبع النوع
 * كما استقرّ منذ تاسك 55، فلا عمود ثالث ولا تسمية مكرّرة في كل شاشة.
 */

/** تسعيرٌ يقيس مقاساً لا يعدّ قطعاً. */
export function isMeasured(type: ServicePricingType): boolean {
    return type !== 'unit';
}

/** لاحقة الوحدة كما تُكتب بجانب رقم: «م²» أو «م» أو لا شيء. */
export function unitSuffix(type: ServicePricingType): string {
    return type === 'sqm' ? 'م²' : type === 'linear' ? 'م' : '';
}

/** «للمتر المربع» أو «للمتر الطولي» — وحدة القياس كما تُقرأ في تسمية حقل. */
export function meterLabel(type: ServicePricingType): string {
    return type === 'linear' ? 'للمتر الطولي' : 'للمتر المربع';
}

/** اسم نوع التسعير كما يُعرض. */
export function pricingTypeLabel(type: ServicePricingType): string {
    return type === 'sqm' ? 'بالمتر المربع' : type === 'linear' ? 'بالمتر الطولي' : 'بالوحدة';
}
