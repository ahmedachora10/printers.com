# تاسكات مستخرجة من «ملاحظات مهمة.pdf»

> المصدر: `ملاحظات مهمة.pdf` (10 صفحات) — تاريخ الاستخراج: 30/07/2026
> كل تاسك يحتوي على: **المطلوب** + **مكان التنفيذ** + **برومبت جاهز للـ AI**.

## قرارات مُتفق عليها (تحسم الغموض في الملف)

| البند | القرار |
|---|---|
| عمود «الفرع» (تاسك 1، 2) | يظهر لدور `super-admin` **فقط** |
| أساس العمولة بعد الضريبة (تاسك 6) | يُطبَّق على **الفواتير الجديدة فقط** — لا مساس بـ `commission_ledger` القديم |
| «الخامات» (تاسك 8) | **إعادة تسمية فقط** لـ «منها تحضير» — بدون حقل جديد |
| خصم الخامات قبل العمولة (صفحة 7) | **مؤجل** حتى يُشرح في الاجتماع — خارج نطاق التنفيذ الحالي |
| «مرتجع» (تاسك 4) | **الاثنان معاً**: سجل `refund` في M14 + حالة فاتورة جديدة `returned` |
| «اليوم الحالي فقط» (تاسك 3) | يشمل: تقرير العمولات + التقرير اليومي + تقرير المبيعات + لوحة التحكم + التحليلات |
| تفاصيل الخدمة (تاسك 5) | نص حر يُطبع في الفاتورة |

---

## 1️⃣ عمود الفرع في شاشة الفواتير

**المطلوب (صفحة 1):** إضافة عمود عند الأدمن يوضح الفرع الصادر منه الفاتورة.

**مكان التنفيذ:**
- `app/Http/Controllers/InvoiceController.php`
- `app/Http/Resources/Invoice/` (الـ Resource المستخدم في الفهرس)
- `resources/js/pages/invoices/index.tsx`
- `resources/js/types/invoice.ts`

**البرومبت:**
```
في شاشة الفواتير /invoices أضف عمود «الفرع» يظهر فقط لدور super-admin.
- في InvoiceController@index حمّل علاقة branch مع الاستعلام (eager load، تجنّب N+1).
- مرّر branchName في الـ API Resource بمفتاح camelCase.
- في resources/js/pages/invoices/index.tsx أضف تعريف العمود مشروطاً بـ
  usePage<SharedData>().props.auth.user.roleName === 'super-admin'.
- أضف فلتر «الفرع» (Select) بجانب فلاتر الحالة والنوع، مع دعمه في FormRequest الفلترة.
- حدّث resources/js/types/invoice.ts.
- اكتب اختبار Pest في tests/Feature/Invoice/ يتحقق أن super-admin يستقبل branchName
  وأن accountant/employee لا يستقبله.
```

---

## 2️⃣ عمود الفرع في شاشة العملاء

**المطلوب (صفحة 2):** إضافة عمود عند الأدمن يوضح العميل تابع لأي فرع.

**مكان التنفيذ:**
- `app/Http/Controllers/CustomerController.php`
- `resources/js/pages/customers/index.tsx`
- ملف تصدير العملاء في `app/Exports/`

**البرومبت:**
```
في شاشة العملاء /customers أضف عمود «الفرع» بنفس نمط تاسك الفواتير (super-admin فقط):
eager load لعلاقة branch، branchName في الـ Resource، عمود مشروط بالدور في
resources/js/pages/customers/index.tsx، وفلتر «الفرع» في شريط الفلاتر.
أضف العمود أيضاً في تصدير Excel للعملاء (للأدمن فقط). اكتب اختبار Pest.
```

---

## 3️⃣ التقارير تبدأ باليوم الحالي + عمود التاريخ + أيام صفرية

**المطلوب (صفحة 3):**
1. إظهار بيانات **اليوم الحالي فقط** افتراضياً، والتصفية تتيح اختيار الأيام — وينطبق على جميع التقارير عند الموظف والمحاسب ومدير الفرع والأدمن.
2. اليوم الذي لا يوجد به أي إيراد يظهر في القائمة بـ **صفر**.
3. إضافة **عمود التاريخ** بحيث تظهر جميع الأيام المحددة بتاريخها عند التصفية.

**مكان التنفيذ:**
- `app/Http/Controllers/CommissionReportController.php`
- `app/Http/Controllers/SalesReportController.php`
- `app/Http/Controllers/DailyReportController.php`
- `app/Http/Controllers/DashboardController.php`
- `app/Http/Controllers/AnalyticsController.php`
- `app/Http/Requests/Report/*FilterRequest.php`
- `resources/js/hooks/use-report-filters.ts`
- `resources/js/pages/reports/**`

**البرومبت:**
```
وحّد سلوك المدى الزمني في كل التقارير:
1) اجعل القيمة الافتراضية from = to = today (بدل الشهر/الفترة الحالية) في
   CommissionReportController وSalesReportController وDailyReportController
   وDashboardController وAnalyticsController، مع إبقاء تغيير المدى من مودال التصفية.
2) عند اختيار مدى أكثر من يوم، أضف عمود «التاريخ» في جدول التفاصيل.
3) ولّد صفاً لكل يوم داخل المدى حتى لو لم يوجد أي إيراد، واعرض SAR 0.00 بدل إخفاء الصف.
   انتبه: now() في هذا المشروع CarbonImmutable — استخدم $cursor = $cursor->addDay()
   داخل الحلقات ولا تكتب $cursor->addDay(); وحدها (تسبب حلقة لا نهائية).
4) طبّق ذلك لكل الأدوار: employee / accountant / branch-admin / super-admin
   مع احترام ResolveReportScope الحالي.
5) حدّث اختبارات Pest القائمة في tests/Feature/Report/ التي تفترض المدى الافتراضي القديم.
```

---

## 4️⃣ تحويل «حذف الفاتورة» إلى «استرجاع» (مرتجع)

**المطلوب (صفحة 4):**
1. استبدال كلمة **«تأكيد الحذف»** بـ **«تأكيد الاسترجاع»**.
2. عند الاسترجاع يبقى الصف ظاهراً كما هو ولا يُحذف، ويُحدَّد باللون الأحمر الفاتح، وفي عمود الحالة تُكتب كلمة **«مرتجع»**.

**مكان التنفيذ:**
- migration جديدة على `service_invoices.status` و `product_invoices.status` (قيمة `returned`)
- `app/Enums/` — enum حالة الفاتورة
- `app/Actions/ServiceInvoice/DeleteServiceInvoiceAction.php` → يُستبدل بـ `ReturnServiceInvoiceAction`
- `app/Actions/ServiceInvoice/Concerns/ReversesServiceInvoiceAccruals.php`
- `app/Actions/Refund/CreateRefundAction.php`
- `resources/js/pages/invoices/index.tsx`
- `lang/ar/`

**البرومبت:**
```
حوّل زر «حذف الفاتورة» إلى «استرجاع الفاتورة» بحيث يجمع بين نظام المرتجعات M14
وحالة فاتورة جديدة:
1) migration تضيف قيمة returned إلى enum status في service_invoices وproduct_invoices،
   وحدّث الـ Enum المقابل في app/Enums/ مع تسمية عربية «مرتجع».
2) أنشئ ReturnServiceInvoiceAction (بدل DeleteServiceInvoiceAction) داخل DB::transaction:
   - ينشئ سجل refund عبر CreateRefundAction (المبلغ + السبب + عكس المخزون حسب قواعد M14).
   - يعكس العمولات غير المدفوعة ونقاط الولاء عبر ReversesServiceInvoiceAccruals.
   - يضبط status = returned. ممنوع soft-delete أو حذف الفاتورة.
   - احترم قاعدة عدم التعديل على commission_ledger: العكس = صف سالب جديد وليس UPDATE.
3) الواجهة في resources/js/pages/invoices/index.tsx:
   - نص المودال والزر: «تأكيد الاسترجاع» بدل «تأكيد الحذف»، وحدّث نص التحذير.
   - الصف المُرتجع يبقى ظاهراً بخلفية bg-red-50 (dark: bg-red-950/30)
     وبادج حالة حمراء نصها «مرتجع».
   - عطّل زر الاسترجاع للفاتورة المُرتجعة مسبقاً.
4) حدّث lang/ar/ وسياسة الصلاحيات (من يملك حق الاسترجاع).
5) اختبارات Pest: الاسترجاع لا يحذف الفاتورة، يضبط الحالة returned، ينشئ سجل refund،
   ويكتب صف عمولة سالب، ولا يمكن استرجاع فاتورة مُرتجعة مرتين.
```

---

## 5️⃣ خانة تفاصيل إضافية أسفل سطر الخدمة في POS

**المطلوب (صفحة 5):** إضافة خانة أو بوكس أسفل الخدمة يمكنه إضافة تفاصيل أخرى للخدمة.
**القرار:** نص حر يُطبع في الفاتورة.

**مكان التنفيذ:**
- migration على `service_invoice_lines` (حقل `notes`)
- `app/Actions/ServiceInvoice/Concerns/WritesServiceInvoiceLines.php`
- `app/Http/Requests/ServiceInvoice/`
- `resources/js/components/pos/cart-table.tsx`
- `resources/js/pages/pos/service/index.tsx`
- `resources/js/pages/invoices/show.tsx` و `resources/js/pages/invoices/print.tsx`

**البرومبت:**
```
أضف حقل ملاحظات نصي حر لكل سطر خدمة:
1) migration: notes TEXT nullable على service_invoice_lines.
2) في resources/js/components/pos/cart-table.tsx أضف تحت كل سطر خدمة textarea صغيرة
   بعنوان «تفاصيل إضافية» (اختيارية، قابلة للطي لتوفير المساحة، rows=2).
3) مرّر القيمة ضمن payload السطر، وأضف قاعدة nullable|string|max:500 في FormRequest،
   واحفظها في WritesServiceInvoiceLines.
4) اعرض النص تحت اسم الخدمة بخط أصغر ولون باهت في:
   resources/js/pages/invoices/show.tsx و resources/js/pages/invoices/print.tsx
   و resources/js/pages/pos/service/print.tsx (يُطبع في فاتورة العميل).
5) تأكد من تحميل القيمة عند تعديل فاتورة قائمة.
6) اختبار Pest للحفظ والاسترجاع.
```

---

## 6️⃣ ⚠️ احتساب العمولة بعد خصم الضريبة (الأولوية القصوى)

**المطلوب (صفحة 6):** عمولة الموظف تُحتسب **بعد خصم الضريبة**.

```
فاتورة  = 100.00 ريال
الصافي  = 100 ÷ 1.15 = 86.95 ريال
العمولة = 86.95 × 50% = 43.47 ريال   ← وليس 50.00 كما يظهر حالياً
```
وينطبق نفس المبدأ على **جميع العمولات الأخرى**.

**القرار:** يُطبَّق على الفواتير الجديدة فقط، بدون تصحيح رجعي للسجلات السابقة.

**مكان التنفيذ:**
- `app/Actions/ServiceInvoice/CalculateServiceInvoiceAction.php`
- `app/Actions/ServiceInvoice/Concerns/WritesServiceInvoiceLines.php`
- `app/Actions/ServiceInvoice/Concerns/SyncsServiceInvoiceAgents.php`
- `resources/js/pages/pos/service/index.tsx` (المعاينة الحية)

**البرومبت:**
```
غيّر أساس احتساب كل العمولات ليكون صافي المبلغ قبل الضريبة بدل المبلغ الإجمالي.
1) في CalculateServiceInvoiceAction احسب:
   netBeforeVat = المبلغ بعد كل الخصومات ÷ (1 + vat_pct/100)
   ثم اشتق منه: عمولة الموظف، عمولة أصحاب العمولة بالسطر (line_commission_amount)،
   وريبيت الوكيل.
2) استخدم حسابات دقيقة (round(..., 2) أو bcmath) — ممنوع تمرير المبالغ كـ float،
   وكل الأعمدة DECIMAL(12,2).
3) طبّق المنطق نفسه في WritesServiceInvoiceLines و SyncsServiceInvoiceAgents
   حتى تتطابق قيم الـ ledger مع العرض.
4) حدّث المعاينة الفورية في resources/js/pages/pos/service/index.tsx وبطاقة الملخص
   («عمولة الموظف (تقديري)» و«عمولات أصحاب العمولة (البنود)») لتطابق ناتج الخادم بالضبط.
5) لا تُعدّل أو تُصحّح صفوف commission_ledger القديمة — التغيير يسري على الفواتير الجديدة فقط.
6) اختبار Pest: فاتورة صافيها 100 + ضريبة 15% (إجمالي 115) بنسبة عمولة 50%
   ← عمولة الموظف = 43.47 وليس 50.00. وأضف حالة اختبار للوكيل ولعمولة السطر.
```

---

## 7️⃣ خصم «الخامات» قبل احتساب العمولة — ⏸️ مؤجل

**المطلوب (صفحة 7):**
```
قيمة الفاتورة = 100 ريال ، الخامات = 20 ريال
100 − ضريبة 13.05        = 86.95
86.95 − خامات 20         = 66.95
66.95 × 50%              = 33.47 ← عمولة الموظف
```

**الحالة:** الملف نفسه ينص على *«الخامات: يتم شرحها في الاجتماع»*، والقرار الحالي هو **تأجيل هذا التاسك** حتى تتضح مصادر مبلغ الخامات (إدخال يدوي / نسبة للخدمة / ربط بالمخزون). يُنفّذ الآن تاسك 6 فقط.

**نقاط تحتاج حسماً في الاجتماع:**
- من أين يأتي مبلغ الخامات؟ إدخال يدوي بالسطر أم قيمة معرّفة على الخدمة أم استهلاك مخزون؟
- هل الخامات لكل سطر أم للفاتورة ككل؟
- هل تُخصم قبل عمولات أصحاب العمولة بالسطر أيضاً أم من عمولة الموظف فقط؟

---

## 8️⃣ تقرير العمولات: «منها تحضير» → «الخامات» + عمود «للعمولات»

**المطلوب (صفحة 8):**
1. استبدال كلمة **«منها تحضير»** بـ **«الخامات»** ويظهر المبلغ في الخانة إن وُجدت الخامة.
2. إضافة عمود **«للعمولات»** يظهر فيه العمولات الخاصة بأصحاب العمولات.

**مكان التنفيذ:**
- `lang/ar/` (نصوص التقرير)
- `app/Http/Controllers/CommissionReportController.php`
- `resources/js/pages/reports/commissions/index.tsx`
- `app/Exports/` (تصدير تقرير العمولات)

**البرومبت:**
```
في تقرير العمولات /reports/commissions:
1) أعد تسمية كل ظهور لـ «منها تحضير» إلى «الخامات» في بطاقات الملخص وجدول
   «العمولات حسب الموظف» وترويسات تصدير Excel و lang/ar. (إعادة تسمية فقط —
   لا حقل جديد ولا تغيير في مصدر البيانات في هذه المرحلة.)
2) أضف عمود جديد «للعمولات» يجمع line_commission_amount من
   pivot service_invoice_agent (عمولات أصحاب العمولة بالسطر) لكل موظف،
   مع صف الإجمالي وبطاقة ملخص مقابلة.
3) أضف العمود إلى تصدير Excel بنفس الترتيب.
4) حدّث اختبارات tests/Feature/Report/ لتغطية العمود الجديد.
   انتبه: commission_ledger.invoice_line_type يخزّن اسم الكلاس الكامل وليس 'service'.
```

---

## 9️⃣ تعديل بيانات العميل والرقم الضريبي من شاشة تعديل الفاتورة

**المطلوب (صفحة 9):** إمكانية تعديل بيانات العميل وإضافة الرقم الضريبي عند الضغط على تعديل الفاتورة.

**مكان التنفيذ:**
- `resources/js/pages/pos/service/index.tsx` (وضع التعديل)
- `app/Actions/ServiceInvoice/UpdateServiceInvoiceAction.php`
- `app/Actions/ServiceInvoice/AttachServiceInvoiceCustomerAction.php`
- `app/Http/Requests/ServiceInvoice/`
- `app/Http/Controllers/ServiceInvoiceController.php`

**البرومبت:**
```
في وضع تعديل فاتورة الخدمة اجعل بطاقة «العميل» قابلة للتحرير:
1) عند اختيار عميل مسجّل: اعرض حقوله (الاسم، رقم الهاتف، الرقم الضريبي) في الوضع
   القابل للتعديل مع زر «حفظ بيانات العميل» يُحدّث سجل customers نفسه.
2) للعميل العابر: اسمح بتعبئة الاسم والهاتف والرقم الضريبي وحفظها على الفاتورة.
3) تحقق من الرقم الضريبي: nullable|digits:15 (15 رقماً بالضبط) في FormRequest،
   مع رسالة خطأ عربية واضحة.
4) وسّع UpdateServiceInvoiceAction / AttachServiceInvoiceCustomerAction لتمرير هذه
   الحقول داخل DB::transaction، مع احترام قيد UNIQUE(phone, branch_id) على العملاء.
5) اعرض الرقم الضريبي في شاشة عرض الفاتورة وفي الطباعة.
6) اختبار Pest: تعديل بيانات عميل من شاشة الفاتورة يُحدّث سجل العميل،
   ورقم ضريبي غير مكوّن من 15 رقماً يُرفض.
```

---

## 🔟 تغيير حالة العمولة عند استرجاع الفاتورة

**المطلوب (صفحة 10):** في قائمة التقارير يتم تغيير الحالة إذا تمت عملية استرجاع الفاتورة.

**مكان التنفيذ:**
- `app/Http/Controllers/CommissionReportController.php`
- `resources/js/pages/reports/commissions/index.tsx`
- `lang/ar/`

**تبعية:** يعتمد على تاسك 4 (حالة `returned`).

**البرومبت:**
```
اربط حالة صف العمولة في تقرير العمولات بحالة فاتورتها:
1) في CommissionReportController اشتق حالة الصف من الفاتورة المرتبطة:
   الفاتورة returned ← حالة العمولة «مرتجعة» ببادج أحمر
   (بدل «معتمدة» / «غير مسددة» / «ملغاة»).
2) استبعد صفوف العمولات المرتجعة من إجمالي «المستحق» في بطاقات الملخص
   وفي جدول «العمولات حسب الموظف».
3) ممنوع تعديل صفوف commission_ledger — الجدول immutable. استنتج الحالة من
   علاقة الفاتورة أو من وجود صف عكس سالب مقابل، ولا تُنفّذ UPDATE.
4) أضف «مرتجعة» لخيارات فلتر الحالة في مودال التصفية ولتصدير Excel.
5) اختبار Pest: استرجاع فاتورة يغيّر حالة صفوف عمولاتها في التقرير
   ويُنقص إجمالي المستحق بالمبلغ نفسه.
```

---

## ترتيب التنفيذ المقترح

| الأولوية | التاسكات | السبب |
|---|---|---|
| 🔴 عالية | 6 | خطأ مالي فعلي في احتساب العمولات |
| 🔴 عالية | 4 ← 10 | مترابطان: حالة «مرتجع» ثم أثرها على التقارير |
| 🟠 متوسطة | 3، 8 | تحسينات جوهرية على التقارير |
| 🟡 عادية | 1، 2، 5، 9 | إضافات واجهة |
| ⏸️ مؤجلة | 7 | بانتظار توضيح الخامات في الاجتماع |
