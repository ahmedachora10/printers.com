<?php

use App\Http\Controllers\AgentCommissionReportController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\AgentPaymentController;
use App\Http\Controllers\AgentPortalController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AppSettingController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\BranchServiceController;
use App\Http\Controllers\CatalogCategoryController;
use App\Http\Controllers\CatalogPriceController;
use App\Http\Controllers\CatalogSubcategoryController;
use App\Http\Controllers\CatalogueController;
use App\Http\Controllers\CityController;
use App\Http\Controllers\CommissionController;
use App\Http\Controllers\CommissionReportController;
use App\Http\Controllers\CouponController;
use App\Http\Controllers\CustomerActivityController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DailyReportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeployController;
use App\Http\Controllers\DeploymentController;
use App\Http\Controllers\EmployeeDeductionController;
use App\Http\Controllers\ExpenseCategoryController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\ExpenseReportController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\IncentiveController;
use App\Http\Controllers\IncentiveReportController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoicePaymentController;
use App\Http\Controllers\InvoiceReceiptController;
use App\Http\Controllers\LoyaltyController;
use App\Http\Controllers\MaterialsReportController;
use App\Http\Controllers\MyIncentiveController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductInvoiceController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\PurchaseRequestController;
use App\Http\Controllers\RefundController;
use App\Http\Controllers\SalesReportController;
use App\Http\Controllers\ServiceInvoiceController;
use App\Http\Controllers\ServicePriceListController;
use App\Http\Controllers\ServiceTemplateController;
use App\Http\Controllers\StockMovementController;
use App\Http\Controllers\StockReconciliationController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\EnsureDeployUiEnabled;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::match(['get', 'post'], 'deploy', DeployController::class)
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->middleware('throttle:5,1')
    ->name('deploy');

// شاشة النشر — خارج بوابة الدخول عمداً: يفتحها السوبر أدمن بدوره، أو حاملُ
// DEPLOY_TOKEN بمفتاحه ولو لم يكن له حسابٌ أصلاً (DeployAccess). وتبقى 404
// للجميع ما لم يُرفع DEPLOY_UI_ENABLED.
Route::middleware(EnsureDeployUiEnabled::class)->group(function () {
    Route::get('deployment', [DeploymentController::class, 'index'])->name('deployment.index');
    Route::post('deployment/unlock', [DeploymentController::class, 'unlock'])
        ->middleware('throttle:5,1')
        ->name('deployment.unlock');
    Route::delete('deployment/unlock', [DeploymentController::class, 'lock'])->name('deployment.lock');
    // يُدفق مخرجاته، فيُستدعى بـ fetch من الشاشة لا بزيارة Inertia.
    Route::post('deployment/run', [DeploymentController::class, 'run'])->name('deployment.run');
});

// M19 — Public service catalogue (no auth).
Route::get('catalogue', [CatalogueController::class, 'index'])->name('catalogue.index');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // In-app notifications (available to every authenticated role).
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::patch('notifications/read-all', [NotificationController::class, 'markAllAsRead'])->name('notifications.read-all');
    Route::patch('notifications/{id}/read', [NotificationController::class, 'markAsRead'])->name('notifications.read');
    Route::delete('notifications/{id}', [NotificationController::class, 'destroy'])->name('notifications.destroy');

    // Stop impersonating — must stay outside any role gate so the impersonated
    // (non-admin) user can return to the original admin account.
    Route::delete('impersonate/leave', [ImpersonationController::class, 'leave'])->name('impersonate.leave');

    // Read-only self-service portal for B2B agents.
    Route::middleware('role:agent')->group(function () {
        Route::get('agent-portal', [AgentPortalController::class, 'index'])->name('agent-portal.index');
    });

    Route::middleware('role:super-admin')->group(function () {
        Route::resource('cities', CityController::class);
        Route::patch('cities/{city}/toggle-status', [CityController::class, 'toggleStatus'])
            ->name('cities.toggle-status');

        Route::resource('branches', BranchController::class)
            ->except(['create', 'edit']);
        Route::patch('branches/{branch}/toggle-status', [BranchController::class, 'toggleStatus'])
            ->name('branches.toggle-status');

        // `store` تعيش في مجموعة مدير الفرع أدناه: الإنشاء متاح للاثنين (تاسك 45).
        Route::resource('service-templates', ServiceTemplateController::class)
            ->except(['create', 'edit', 'store']);
    });

    // M20 + تاسك 47 — every branch builds its own catalogue. The whole module
    // is open to the branch admin, and ownership (not the route) is what
    // confines them: the policies let them touch only the rows their branch
    // wrote, and the Form Requests pin `branch_id` to their branch so a
    // hand-rolled request cannot reach into the shared catalogue or another
    // branch.
    Route::middleware('role:branch-admin|super-admin')->group(function () {

        Route::resource('payment-methods', PaymentMethodController::class)
            ->parameters(['payment-methods' => 'paymentMethod'])
            ->only(['store', 'update', 'destroy']);
        Route::patch('payment-methods/{paymentMethod}/toggle-status', [PaymentMethodController::class, 'toggleStatus'])
            ->name('payment-methods.toggle-status');

        Route::prefix('admin/catalogue')->name('admin.catalogue.')->group(function () {
            // Full-catalogue Excel export / import (categories + subcategories + prices)

            Route::controller(CatalogCategoryController::class)
                ->group(function () {
                    Route::get('export', 'export')->name('export');
                    Route::get('import/template', 'importTemplate')->name('import.template');
                    Route::post('import/preview', 'importPreview')->name('import.preview');
                    Route::post('import', 'import')->name('import');

                    // Categories
                    Route::get('/', 'index')->name('categories.index');
                    Route::post('categories', 'store')->name('categories.store');
                    Route::post('categories/{category}', 'update')->name('categories.update');
                    Route::delete('categories/{category}', 'destroy')->name('categories.destroy');
                    Route::patch('categories/{category}/toggle-status', 'toggleStatus')->name('categories.toggle-status');
                });

            // Subcategories (listed per category)
            Route::get('categories/{category}/subcategories', [CatalogSubcategoryController::class, 'index'])->name('subcategories.index');
            Route::post('subcategories', [CatalogSubcategoryController::class, 'store'])->name('subcategories.store');
            Route::post('subcategories/{subcategory}', [CatalogSubcategoryController::class, 'update'])->name('subcategories.update');
            Route::delete('subcategories/{subcategory}', [CatalogSubcategoryController::class, 'destroy'])->name('subcategories.destroy');
            Route::patch('subcategories/{subcategory}/toggle-status', [CatalogSubcategoryController::class, 'toggleStatus'])->name('subcategories.toggle-status');

            // Prices (listed per subcategory)
            Route::get('subcategories/{subcategory}/prices', [CatalogPriceController::class, 'index'])->name('prices.index');
            Route::get('subcategories/{subcategory}/prices/export', [CatalogPriceController::class, 'export'])->name('prices.export');
            Route::get('subcategories/{subcategory}/prices/import/template', [CatalogPriceController::class, 'importTemplate'])->name('prices.import.template');
            Route::post('subcategories/{subcategory}/prices/import/preview', [CatalogPriceController::class, 'importPreview'])->name('prices.import.preview');
            Route::post('subcategories/{subcategory}/prices/import', [CatalogPriceController::class, 'import'])->name('prices.import');
            Route::post('prices', [CatalogPriceController::class, 'store'])->name('prices.store');
            Route::put('prices/{price}', [CatalogPriceController::class, 'update'])->name('prices.update');
            Route::delete('prices/{price}', [CatalogPriceController::class, 'destroy'])->name('prices.destroy');
            Route::patch('prices/{price}/toggle-status', [CatalogPriceController::class, 'toggleStatus'])->name('prices.toggle-status');
        });
    });

    Route::middleware('role:branch-admin|super-admin|accountant')->group(function () {
        Route::prefix('pos')->name('pos.')->group(function () {
            Route::get('product', [ProductInvoiceController::class, 'create'])->name('product.create');
            Route::post('product', [ProductInvoiceController::class, 'store'])->name('product.store');
            Route::get('product/{invoice}/print', [ProductInvoiceController::class, 'print'])->name('product.print');
        });

        Route::get('inventory/stock-movements', [StockMovementController::class, 'index'])
            ->name('inventory.stock-movements.index');

        // Read-only procurement views are open to accountants for audit; all
        // mutations live in the branch-admin/super-admin group below.
        Route::get('inventory/suppliers', [SupplierController::class, 'index'])
            ->name('inventory.suppliers.index');
        Route::get('inventory/purchase-orders', [PurchaseOrderController::class, 'index'])
            ->name('inventory.purchase-orders.index');
        Route::get('inventory/purchase-orders/{purchase_order}', [PurchaseOrderController::class, 'show'])
            ->whereNumber('purchase_order')
            ->name('inventory.purchase-orders.show');
        Route::get('inventory/stock-reconciliations', [StockReconciliationController::class, 'index'])
            ->name('inventory.stock-reconciliations.index');
        Route::get('inventory/stock-reconciliations/{stock_reconciliation}', [StockReconciliationController::class, 'show'])
            ->whereNumber('stock_reconciliation')
            ->name('inventory.stock-reconciliations.show');

        Route::get('refunds/lookup', [RefundController::class, 'lookup'])->name('refunds.lookup');
        Route::get('refunds', [RefundController::class, 'index'])->name('refunds.index');
        Route::post('refunds', [RefundController::class, 'store'])->name('refunds.store');

        Route::resource('expenses', ExpenseController::class)
            ->only(['index', 'store', 'update', 'destroy']);
    });

    Route::middleware('role:branch-admin|super-admin|employee')->group(function () {
        Route::prefix('pos')->name('pos.')->group(function () {
            Route::get('service', [ServiceInvoiceController::class, 'create'])->name('service.create');
            Route::post('service', [ServiceInvoiceController::class, 'store'])->name('service.store');
            Route::get('service/{invoice}/print', [ServiceInvoiceController::class, 'print'])->name('service.print');
            // Return (DUE or PAID) is the owning employee's alone — an accountant
            // cancels or refunds instead — and ServiceInvoicePolicy says so per invoice.
            Route::post('service/{invoice}/return', [ServiceInvoiceController::class, 'returnInvoice'])->name('service.return');
        });
    });

    // تاسك 70: تعديل فاتورة خدمة معلّقة — صاحبُها الموظف، أو مراجعٌ في فرعها
    // (مدير الفرع أو المحاسب) يصحّح تكلفة الخامات قبل الاعتماد. الميدلوير يفتح الباب
    // للأدوار الأربعة، والصلاحية النهائية تبقى لـServiceInvoicePolicy::update لا له.
    Route::middleware('role:branch-admin|super-admin|employee|accountant')->group(function () {
        Route::prefix('pos')->name('pos.')->group(function () {
            Route::get('service/{invoice}/edit', [ServiceInvoiceController::class, 'edit'])->name('service.edit');
            Route::put('service/{invoice}', [ServiceInvoiceController::class, 'update'])->name('service.update');
        });
    });

    // Due service-invoice review queue — an accountant or branch admin settles
    // or cancels invoices raised by employees.
    Route::middleware('role:branch-admin|super-admin|accountant')->group(function () {
        Route::prefix('invoices/service')->name('invoices.service.')->group(function () {
            Route::get('review', [ServiceInvoiceController::class, 'review'])->name('review');
            Route::patch('{invoice}/pay', [ServiceInvoiceController::class, 'markPaid'])->name('pay');
            Route::patch('{invoice}/cancel', [ServiceInvoiceController::class, 'cancel'])->name('cancel');
            Route::patch('{invoice}/payment-method', [ServiceInvoiceController::class, 'updatePaymentMethod'])->name('update-payment-method');
            Route::post('{invoice}/receipt', [InvoiceReceiptController::class, 'store'])->name('receipt');
        });

        // دفعات الفاتورة (عربون + دفعات لاحقة) — لكلا نوعي الفواتير. من يعتمد
        // الفاتورة هو من يسجّل تحصيلها؛ التحقق النهائي في InvoicePaymentController.
        Route::post('invoices/{type}/{id}/payments', [InvoicePaymentController::class, 'store'])
            ->whereIn('type', ['product', 'service'])->whereNumber('id')
            ->name('invoices.payments.store');
    });

    // Customer details of a service invoice — shared by the accountant's review
    // queue and the owning employee's POS edit screen. Who may touch which
    // invoice is decided per-invoice by ServiceInvoicePolicy::updateCustomer.
    Route::middleware('role:branch-admin|super-admin|accountant|employee')->group(function () {
        Route::patch('invoices/service/{invoice}/customer', [ServiceInvoiceController::class, 'updateCustomer'])
            ->name('invoices.service.update-customer');

        // «تم تسليم العمل» (تاسك 31): يختمه مَن يسلّم العمل للعميل عند الطاولة —
        // صاحب الفاتورة أو مدير الفرع أو المحاسب. القرار لكل فاتورة على حدة في
        // ServiceInvoicePolicy::deliver، لا على الميدلوير وحده.
        Route::post('invoices/service/{invoice}/deliver', [ServiceInvoiceController::class, 'deliver'])
            ->name('invoices.service.deliver');
    });

    // ⚠️ يبقيان في متناول المحاسب بعد تاسك 40: نقطة بيع المنتجات تحتاج البحث عن
    // عميل وقراءة رصيده لربطه بفاتورة آجلة. ويجب أن يسبقا Route::resource أدناه
    // وإلا التقط customers/{customer} المسارَ الثابت وحاول ربطه كمعرّف.
    Route::middleware('role:branch-admin|super-admin|accountant|employee')->group(function () {
        Route::get('pos/customers/search', [CustomerController::class, 'posSearch'])
            ->name('pos.customers.search');
        Route::get('customers/outstanding-balance', [CustomerController::class, 'outstandingBalance'])
            ->name('customers.outstanding-balance');
    });

    // «حوافزي وحسوماتي»: وجه الموظف من شاشة الحوافز المغلقة على الإدارة. قراءةٌ
    // لصفوفه هو وحدها — المتحكّم لا يقرأ معرِّفاً من الطلب أصلاً.
    Route::middleware('role:employee')->group(function () {
        Route::get('my-incentives', [MyIncentiveController::class, 'index'])
            ->name('my-incentives.index');
    });

    // سجلّ العملاء: مدير الفرع والسوبر أدمن والموظف (الموظف يسجّل عميل فاتورته).
    // المحاسب خارجه بقرار العميل (تاسك 40) — ولا يزال يبحث عن العميل داخل نقطة
    // البيع، ويرى اسمه على الفاتورة التي يحصّلها.
    Route::middleware('role:branch-admin|super-admin|employee')->group(function () {
        Route::get('customers/export', [CustomerController::class, 'export'])
            ->name('customers.export');
        Route::resource('customers', CustomerController::class);
        Route::post('customers/{customer}/merge', [CustomerController::class, 'merge'])
            ->name('customers.merge');
        Route::patch('customers/{customer}/toggle-status', [CustomerController::class, 'toggleStatus'])
            ->name('customers.toggle-status');
        // التنزيل اليدوي لمستوى الولاء: المحرّك يرقّي ولا ينزّل، فهذا منفذه الوحيد.
        Route::patch('customers/{customer}/tier', [CustomerController::class, 'overrideTier'])
            ->name('customers.override-tier');

        // CRM & customer analytics (M23): unified activity timeline + purchase
        // analytics; visible to whoever can view the customer profile.
        Route::get('customers/{customer}/activity', [CustomerActivityController::class, 'show'])
            ->name('customers.activity');
    });

    Route::middleware('role:branch-admin|super-admin|accountant|employee')->group(function () {
        // Read-only in-app price list over the same catalogue tree as the
        // public M19 page — staff reference it while quoting a customer.
        Route::get('services/price-list', [ServicePriceListController::class, 'index'])
            ->name('services.price-list');

        Route::prefix('invoices')->name('invoices.')->group(function () {
            Route::get('/', [InvoiceController::class, 'index'])->name('index');
            Route::get('{type}/{id}', [InvoiceController::class, 'show'])
                ->whereIn('type', ['product', 'service'])->whereNumber('id')->name('show');
            Route::get('{type}/{id}/print', [InvoiceController::class, 'print'])
                ->whereIn('type', ['product', 'service'])->whereNumber('id')->name('print');
            Route::get('{type}/{id}/receipt', [InvoiceReceiptController::class, 'show'])
                ->whereIn('type', ['product', 'service'])->whereNumber('id')->name('receipt');

            // إيصال دفعة بعينها — الصلاحية مأخوذة من الفاتورة الأم داخل المتحكِّم.
            Route::get('payments/{payment}/receipt', [InvoiceReceiptController::class, 'payment'])
                ->name('payments.receipt');
        });

        // Employee commission report (M18): managers see their branch; employees
        // see only their own rows — scoping is enforced in the controller.
        Route::get('reports/commissions/export', [CommissionReportController::class, 'export'])
            ->name('reports.commissions.export');
        Route::get('reports/commissions', [CommissionReportController::class, 'index'])
            ->name('reports.commissions');

        // Internal purchase requests: employees and accountants raise them, the
        // branch admin decides and may turn an approved one into an M29
        // purchase order. Visibility is narrowed by PurchaseRequest::visibleTo
        // and each action by PurchaseRequestPolicy.
        Route::prefix('purchase-requests')->name('purchase-requests.')->group(function () {
            Route::get('/', [PurchaseRequestController::class, 'index'])->name('index');
            Route::post('/', [PurchaseRequestController::class, 'store'])->name('store');
            Route::patch('{purchase_request}/approve', [PurchaseRequestController::class, 'approve'])
                ->name('approve');
            Route::patch('{purchase_request}/reject', [PurchaseRequestController::class, 'reject'])
                ->name('reject');
            Route::post('{purchase_request}/convert', [PurchaseRequestController::class, 'convert'])
                ->name('convert');
        });
    });

    // Sales report (M17): realized revenue over paid invoices. Managers and
    // accountants only; scoped to own branch (super-admin picks freely).
    Route::middleware('role:branch-admin|super-admin|accountant')->group(function () {
        Route::get('reports/sales/export', [SalesReportController::class, 'export'])
            ->name('reports.sales.export');
        Route::get('reports/sales', [SalesReportController::class, 'index'])
            ->name('reports.sales');

        // Daily report: per-day product/service sales, commission, purchases,
        // VAT and net remaining. Same audience and branch scoping as sales.
        Route::get('reports/daily/export', [DailyReportController::class, 'export'])
            ->name('reports.daily.export');
        Route::get('reports/daily', [DailyReportController::class, 'index'])
            ->name('reports.daily');

        // Expense report (تاسك 32): the aggregate reading of /expenses — totals,
        // per-category and per-day breakdowns, and a drill-down. Same audience
        // and branch scoping as the sales report.
        Route::get('reports/expenses/export', [ExpenseReportController::class, 'export'])
            ->name('reports.expenses.export');
        Route::get('reports/expenses', [ExpenseReportController::class, 'index'])
            ->name('reports.expenses');

        // استهلاك خامات الخدمات من المخزون: ما صُرف وما عاد وبكم — القراءة
        // المجمَّعة لحركات المخزون التي تكتبها اعتماداتُ فواتير الخدمات.
        Route::get('reports/materials/export', [MaterialsReportController::class, 'export'])
            ->name('reports.materials.export');
        Route::get('reports/materials', [MaterialsReportController::class, 'index'])
            ->name('reports.materials');

        // Agent (مندوب) commissions: what each agent earned and what is still
        // owed — the counter side of the agent portal.
        Route::get('reports/agent-commissions/export', [AgentCommissionReportController::class, 'export'])
            ->name('reports.agent-commissions.export');
        Route::get('reports/agent-commissions', [AgentCommissionReportController::class, 'index'])
            ->name('reports.agent-commissions');

        // Advanced analytics (M25): Recharts dashboards over paid invoices and
        // loyalty activity, same audience and scoping as the sales report.
        Route::get('analytics', [AnalyticsController::class, 'index'])
            ->name('analytics.index');
    });

    // صرف عمولات المناديب — المحاسب هو من يصرف (تاسك 41)، بينما بيانات المندوب
    // نفسها خارج نطاقه تماماً (تاسك 40). التحقق النهائي لكل مندوب على حدة يقع
    // في المتحكِّم عبر AgentPolicy::pay، لا على الميدلوير وحده.
    Route::middleware('role:branch-admin|super-admin|accountant')->group(function () {
        Route::resource('agent-payments', AgentPaymentController::class)
            ->only(['index', 'store']);
    });

    Route::middleware('role:branch-admin|super-admin')->group(function () {
        // بيانات المناديب لمدير الفرع وحده — نُزعت من المحاسب في تاسك 40.
        Route::resource('agents', AgentController::class)
            ->only(['index', 'store', 'update', 'destroy']);

        Route::patch('users/{user}/toggle-status', [UserController::class, 'toggleStatus'])
            ->name('users.toggle-status');
        Route::get('users/{user}/service-commissions', [UserController::class, 'showServiceCommissions'])
            ->name('users.service-commissions.show');
        Route::put('users/{user}/service-commissions', [UserController::class, 'updateServiceCommissions'])
            ->name('users.service-commissions.update');
        Route::resource('users', UserController::class)
            ->only(['index', 'show', 'store', 'update', 'destroy']);

        Route::post('users/{user}/impersonate', [ImpersonationController::class, 'start'])
            ->name('users.impersonate');

        Route::get('app-settings', [AppSettingController::class, 'index'])->name('app-settings.index');
        Route::put('app-settings/general', [AppSettingController::class, 'updateGeneral'])->name('app-settings.update-general');
        Route::put('app-settings/branch-profile', [AppSettingController::class, 'updateBranchProfile'])->name('app-settings.update-branch-profile');
        Route::put('app-settings/inventory-alerts', [AppSettingController::class, 'updateInventoryAlerts'])->name('app-settings.update-inventory-alerts');
        Route::put('app-settings/payment-methods', [AppSettingController::class, 'updatePaymentMethods'])->name('app-settings.update-payment-methods');
        Route::put('app-settings/loyalty', [AppSettingController::class, 'updateLoyalty'])->name('app-settings.update-loyalty');

        // تاسك 45: مدير الفرع ينشئ خدمة جديدة (مملوكة لفرعه) من شاشة خدمات
        // الفرع دون الرجوع للأدمن؛ والسوبر أدمن يصلها من شاشته وينشئ بها عامة.
        Route::post('service-templates', [ServiceTemplateController::class, 'store'])
            ->name('service-templates.store');

        Route::put('branch-services/{branchService}/employee-commissions', [BranchServiceController::class, 'updateEmployeeCommissions'])
            ->name('branch-services.employee-commissions.update');

        // تاسك 50: خامات المخزون التي تستهلكها الخدمة — تُخصم عند اعتماد الفاتورة.
        Route::put('branch-services/{branchService}/materials', [BranchServiceController::class, 'updateMaterials'])
            ->name('branch-services.materials.update');
        // تصدير/استيراد خدمات الفرع (ورقتان: الخدمات وعمولات الموظفين). تُسجَّل
        // قبل الـresource على سنّة تاسك 72، فلا يبتلع مسارُ موردٍ لاحقٌ مساراتِها.
        Route::controller(BranchServiceController::class)->group(function () {
            Route::get('branch-services/export', 'export')->name('branch-services.export');
            Route::get('branch-services/import/template', 'importTemplate')->name('branch-services.import.template');
            Route::post('branch-services/import/preview', 'importPreview')->name('branch-services.import.preview');
            Route::post('branch-services/import', 'import')->name('branch-services.import');
        });

        Route::resource('branch-services', BranchServiceController::class)
            ->only(['index', 'store', 'update', 'destroy']);

        // تاسك 72: تصدير/استيراد Excel — تُسجَّل قبل الـresource كي لا يبتلع
        // `product-categories/{productCategory}` مساراتِها.
        Route::controller(ProductCategoryController::class)->group(function () {
            Route::get('product-categories/export', 'export')->name('product-categories.export');
            Route::get('product-categories/import/template', 'importTemplate')->name('product-categories.import.template');
            Route::post('product-categories/import/preview', 'importPreview')->name('product-categories.import.preview');
            Route::post('product-categories/import', 'import')->name('product-categories.import');
        });

        Route::patch('product-categories/{productCategory}/toggle-status', [ProductCategoryController::class, 'toggleStatus'])
            ->name('product-categories.toggle-status');
        Route::resource('product-categories', ProductCategoryController::class)
            ->parameters(['product-categories' => 'productCategory'])
            ->only(['index', 'store', 'update', 'destroy']);

        Route::patch('expense-categories/{expenseCategory}/toggle-status', [ExpenseCategoryController::class, 'toggleStatus'])
            ->name('expense-categories.toggle-status');
        Route::resource('expense-categories', ExpenseCategoryController::class)
            ->parameters(['expense-categories' => 'expenseCategory'])
            ->only(['index', 'store', 'update', 'destroy']);

        Route::patch('coupons/{coupon}/toggle-status', [CouponController::class, 'toggleStatus'])
            ->name('coupons.toggle-status');
        Route::resource('coupons', CouponController::class)
            ->only(['index', 'store', 'update', 'destroy']);

        Route::get('commissions', [CommissionController::class, 'index'])->name('commissions.index');
        Route::post('commissions/pay', [CommissionController::class, 'pay'])->name('commissions.pay');

        Route::get('loyalty', [LoyaltyController::class, 'index'])->name('loyalty.index');

        Route::get('incentives', [IncentiveController::class, 'index'])->name('incentives.index');
        Route::post('incentives', [IncentiveController::class, 'store'])->name('incentives.store');
        Route::post('incentives/recalculate', [IncentiveController::class, 'recalculate'])->name('incentives.recalculate');
        Route::put('incentives/{incentive_plan}', [IncentiveController::class, 'update'])->name('incentives.update');
        Route::delete('incentives/{incentive_plan}', [IncentiveController::class, 'destroy'])->name('incentives.destroy');
        Route::post('incentives/{incentive_plan}/pay', [IncentiveController::class, 'pay'])->name('incentives.pay');

        // تاسك 74: حسومات الموظفين — بند مستقل تحت الحوافز، في المجموعة نفسها:
        // «للإدارة صلاحية تطبيق الخصم» يحقّقها role:branch-admin|super-admin.
        Route::post('employee-deductions', [EmployeeDeductionController::class, 'store'])
            ->name('employee-deductions.store');
        Route::delete('employee-deductions/{employee_deduction}', [EmployeeDeductionController::class, 'destroy'])
            ->name('employee-deductions.destroy');

        // تقرير الحوافز والخصومات: القراءة المجمَّعة للبندين معاً. جمهوره جمهور
        // الشاشة نفسها — الإدارة وحدها — لا جمهور بقية التقارير: أرقام الرواتب
        // ليست من شأن المحاسب.
        Route::get('reports/incentives/export', [IncentiveReportController::class, 'export'])
            ->name('reports.incentives.export');
        Route::get('reports/incentives', [IncentiveReportController::class, 'index'])
            ->name('reports.incentives');

        Route::prefix('inventory')->name('inventory.')->group(function () {
            // تاسك 72: تصدير/استيراد Excel — قبل الـresource لنفس سبب الفئات.
            Route::controller(ProductController::class)->group(function () {
                Route::get('products/export', 'export')->name('products.export');
                Route::get('products/import/template', 'importTemplate')->name('products.import.template');
                Route::post('products/import/preview', 'importPreview')->name('products.import.preview');
                Route::post('products/import', 'import')->name('products.import');
            });

            Route::patch('products/{product}/toggle-status', [ProductController::class, 'toggleStatus'])
                ->name('products.toggle-status');
            Route::resource('products', ProductController::class)
                ->only(['index', 'store', 'update', 'destroy']);

            Route::post('stock-movements', [StockMovementController::class, 'store'])
                ->name('stock-movements.store');

            Route::patch('suppliers/{supplier}/toggle-status', [SupplierController::class, 'toggleStatus'])
                ->name('suppliers.toggle-status');
            Route::resource('suppliers', SupplierController::class)
                ->only(['store', 'update', 'destroy']);

            Route::resource('purchase-orders', PurchaseOrderController::class)
                ->only(['store', 'update', 'destroy']);
            Route::patch('purchase-orders/{purchase_order}/sent', [PurchaseOrderController::class, 'markSent'])
                ->name('purchase-orders.sent');
            Route::post('purchase-orders/{purchase_order}/receive', [PurchaseOrderController::class, 'receive'])
                ->name('purchase-orders.receive');
            Route::patch('purchase-orders/{purchase_order}/cancel', [PurchaseOrderController::class, 'cancel'])
                ->name('purchase-orders.cancel');

            Route::post('stock-reconciliations', [StockReconciliationController::class, 'store'])
                ->name('stock-reconciliations.store');
            Route::put('stock-reconciliations/{stock_reconciliation}/counts', [StockReconciliationController::class, 'updateCounts'])
                ->name('stock-reconciliations.counts');
            Route::post('stock-reconciliations/{stock_reconciliation}/complete', [StockReconciliationController::class, 'complete'])
                ->name('stock-reconciliations.complete');
            Route::delete('stock-reconciliations/{stock_reconciliation}', [StockReconciliationController::class, 'destroy'])
                ->name('stock-reconciliations.destroy');
        });
    });

    Route::get('coupons/validate', [CouponController::class, 'validateCoupon'])
        ->name('coupons.validate');

});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
