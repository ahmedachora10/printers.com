<?php

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
use App\Http\Controllers\ExpenseCategoryController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\IncentiveController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoiceReceiptController;
use App\Http\Controllers\LoyaltyController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductInvoiceController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\RefundController;
use App\Http\Controllers\SalesReportController;
use App\Http\Controllers\ServiceInvoiceController;
use App\Http\Controllers\ServiceTemplateController;
use App\Http\Controllers\StockMovementController;
use App\Http\Controllers\StockReconciliationController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

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

        Route::resource('service-templates', ServiceTemplateController::class)
            ->except(['create', 'edit']);

        Route::resource('payment-methods', PaymentMethodController::class)
            ->parameters(['payment-methods' => 'paymentMethod'])
            ->only(['store', 'update', 'destroy']);
        Route::patch('payment-methods/{paymentMethod}/toggle-status', [PaymentMethodController::class, 'toggleStatus'])
            ->name('payment-methods.toggle-status');

        // M20 — Catalogue CRUD (admin).
        Route::prefix('admin/catalogue')->name('admin.catalogue.')->group(function () {
            // Full-catalogue Excel export / import (categories + subcategories + prices)
            Route::get('export', [CatalogCategoryController::class, 'export'])->name('export');
            Route::post('import', [CatalogCategoryController::class, 'import'])->name('import');

            // Categories
            Route::get('/', [CatalogCategoryController::class, 'index'])->name('categories.index');
            Route::post('categories', [CatalogCategoryController::class, 'store'])->name('categories.store');
            Route::post('categories/{category}', [CatalogCategoryController::class, 'update'])->name('categories.update');
            Route::delete('categories/{category}', [CatalogCategoryController::class, 'destroy'])->name('categories.destroy');
            Route::patch('categories/{category}/toggle-status', [CatalogCategoryController::class, 'toggleStatus'])->name('categories.toggle-status');

            // Subcategories (listed per category)
            Route::get('categories/{category}/subcategories', [CatalogSubcategoryController::class, 'index'])->name('subcategories.index');
            Route::post('subcategories', [CatalogSubcategoryController::class, 'store'])->name('subcategories.store');
            Route::post('subcategories/{subcategory}', [CatalogSubcategoryController::class, 'update'])->name('subcategories.update');
            Route::delete('subcategories/{subcategory}', [CatalogSubcategoryController::class, 'destroy'])->name('subcategories.destroy');
            Route::patch('subcategories/{subcategory}/toggle-status', [CatalogSubcategoryController::class, 'toggleStatus'])->name('subcategories.toggle-status');

            // Prices (listed per subcategory)
            Route::get('subcategories/{subcategory}/prices', [CatalogPriceController::class, 'index'])->name('prices.index');
            Route::get('subcategories/{subcategory}/prices/export', [CatalogPriceController::class, 'export'])->name('prices.export');
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

        Route::resource('agents', AgentController::class)
            ->only(['index', 'store', 'update', 'destroy']);
    });

    Route::middleware('role:branch-admin|super-admin|employee')->group(function () {
        Route::prefix('pos')->name('pos.')->group(function () {
            Route::get('service', [ServiceInvoiceController::class, 'create'])->name('service.create');
            Route::post('service', [ServiceInvoiceController::class, 'store'])->name('service.store');
            Route::get('service/{invoice}/print', [ServiceInvoiceController::class, 'print'])->name('service.print');
            // Owner-employee edit (DUE only) and delete (DUE or PAID) — authorized
            // per-invoice by ServiceInvoicePolicy, never by an accountant.
            Route::get('service/{invoice}/edit', [ServiceInvoiceController::class, 'edit'])->name('service.edit');
            Route::put('service/{invoice}', [ServiceInvoiceController::class, 'update'])->name('service.update');
            // Tax number only — the rest of the customer record stays with the
            // accountant via invoices.service.update-customer.
            Route::patch('service/{invoice}/tax-number', [ServiceInvoiceController::class, 'updateTaxNumber'])
                ->name('service.tax-number');
            Route::delete('service/{invoice}', [ServiceInvoiceController::class, 'destroy'])->name('service.destroy');
        });
    });

    // Due service-invoice review queue — an accountant or branch admin settles
    // or cancels invoices raised by employees.
    Route::middleware('role:branch-admin|super-admin|accountant')->group(function () {
        Route::prefix('invoices/service')->name('invoices.service.')->group(function () {
            Route::get('review', [ServiceInvoiceController::class, 'review'])->name('review');
            Route::patch('{invoice}/pay', [ServiceInvoiceController::class, 'markPaid'])->name('pay');
            Route::patch('{invoice}/cancel', [ServiceInvoiceController::class, 'cancel'])->name('cancel');
            Route::patch('{invoice}/customer', [ServiceInvoiceController::class, 'updateCustomer'])->name('update-customer');
            Route::patch('{invoice}/payment-method', [ServiceInvoiceController::class, 'updatePaymentMethod'])->name('update-payment-method');
            Route::post('{invoice}/receipt', [InvoiceReceiptController::class, 'store'])->name('receipt');
        });
    });

    Route::middleware('role:branch-admin|super-admin|accountant|employee')->group(function () {
        Route::get('pos/customers/search', [CustomerController::class, 'posSearch'])
            ->name('pos.customers.search');
        Route::get('customers/outstanding-balance', [CustomerController::class, 'outstandingBalance'])
            ->name('customers.outstanding-balance');
        Route::get('customers/export', [CustomerController::class, 'export'])
            ->name('customers.export');
        Route::resource('customers', CustomerController::class);
        Route::post('customers/{customer}/merge', [CustomerController::class, 'merge'])
            ->name('customers.merge');
        Route::patch('customers/{customer}/toggle-status', [CustomerController::class, 'toggleStatus'])
            ->name('customers.toggle-status');

        // CRM & customer analytics (M23): unified activity timeline + purchase
        // analytics; visible to whoever can view the customer profile.
        Route::get('customers/{customer}/activity', [CustomerActivityController::class, 'show'])
            ->name('customers.activity');

        Route::prefix('invoices')->name('invoices.')->group(function () {
            Route::get('/', [InvoiceController::class, 'index'])->name('index');
            Route::get('{type}/{id}', [InvoiceController::class, 'show'])
                ->whereIn('type', ['product', 'service'])->whereNumber('id')->name('show');
            Route::get('{type}/{id}/print', [InvoiceController::class, 'print'])
                ->whereIn('type', ['product', 'service'])->whereNumber('id')->name('print');
            Route::get('{type}/{id}/receipt', [InvoiceReceiptController::class, 'show'])
                ->whereIn('type', ['product', 'service'])->whereNumber('id')->name('receipt');
        });

        // Employee commission report (M18): managers see their branch; employees
        // see only their own rows — scoping is enforced in the controller.
        Route::get('reports/commissions/export', [CommissionReportController::class, 'export'])
            ->name('reports.commissions.export');
        Route::get('reports/commissions', [CommissionReportController::class, 'index'])
            ->name('reports.commissions');
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

        // Advanced analytics (M25): Recharts dashboards over paid invoices and
        // loyalty activity, same audience and scoping as the sales report.
        Route::get('analytics', [AnalyticsController::class, 'index'])
            ->name('analytics.index');
    });

    Route::middleware('role:branch-admin|super-admin')->group(function () {
        Route::resource('agent-payments', AgentPaymentController::class)
            ->only(['index', 'store']);

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
        Route::put('app-settings/inventory-alerts', [AppSettingController::class, 'updateInventoryAlerts'])->name('app-settings.update-inventory-alerts');
        Route::put('app-settings/payment-methods', [AppSettingController::class, 'updatePaymentMethods'])->name('app-settings.update-payment-methods');
        Route::put('app-settings/loyalty', [AppSettingController::class, 'updateLoyalty'])->name('app-settings.update-loyalty');

        Route::put('branch-services/{branchService}/employee-commissions', [BranchServiceController::class, 'updateEmployeeCommissions'])
            ->name('branch-services.employee-commissions.update');
        Route::resource('branch-services', BranchServiceController::class)
            ->only(['index', 'store', 'update', 'destroy']);

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

        Route::prefix('inventory')->name('inventory.')->group(function () {
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
