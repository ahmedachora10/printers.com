<?php

use App\Http\Controllers\AgentController;
use App\Http\Controllers\AgentPaymentController;
use App\Http\Controllers\AgentPortalController;
use App\Http\Controllers\AppSettingController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\BranchServiceController;
use App\Http\Controllers\CityController;
use App\Http\Controllers\CommissionController;
use App\Http\Controllers\CouponController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\ExpenseCategoryController;
use App\Http\Controllers\ExpenseController;
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
use App\Http\Controllers\ServiceInvoiceController;
use App\Http\Controllers\ServiceTemplateController;
use App\Http\Controllers\StockMovementController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');

    // In-app notifications (available to every authenticated role).
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::patch('notifications/read-all', [NotificationController::class, 'markAllAsRead'])->name('notifications.read-all');
    Route::patch('notifications/{id}/read', [NotificationController::class, 'markAsRead'])->name('notifications.read');
    Route::delete('notifications/{id}', [NotificationController::class, 'destroy'])->name('notifications.destroy');

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
        });
    });

    // Due service-invoice review queue — an accountant or branch admin settles
    // or cancels invoices raised by employees.
    Route::middleware('role:branch-admin|super-admin|accountant')->group(function () {
        Route::prefix('invoices/service')->name('invoices.service.')->group(function () {
            Route::get('review', [ServiceInvoiceController::class, 'review'])->name('review');
            Route::patch('{invoice}/pay', [ServiceInvoiceController::class, 'markPaid'])->name('pay');
            Route::patch('{invoice}/cancel', [ServiceInvoiceController::class, 'cancel'])->name('cancel');
            Route::post('{invoice}/receipt', [InvoiceReceiptController::class, 'store'])->name('receipt');
        });
    });

    Route::middleware('role:branch-admin|super-admin|accountant|employee')->group(function () {
        Route::get('customers/outstanding-balance', [CustomerController::class, 'outstandingBalance'])
            ->name('customers.outstanding-balance');
        Route::get('customers/export', [CustomerController::class, 'export'])
            ->name('customers.export');
        Route::resource('customers', CustomerController::class);
        Route::post('customers/{customer}/merge', [CustomerController::class, 'merge'])
            ->name('customers.merge');
        Route::patch('customers/{customer}/toggle-status', [CustomerController::class, 'toggleStatus'])
            ->name('customers.toggle-status');

        Route::prefix('invoices')->name('invoices.')->group(function () {
            Route::get('/', [InvoiceController::class, 'index'])->name('index');
            Route::get('{type}/{id}', [InvoiceController::class, 'show'])
                ->whereIn('type', ['product', 'service'])->whereNumber('id')->name('show');
            Route::get('{type}/{id}/print', [InvoiceController::class, 'print'])
                ->whereIn('type', ['product', 'service'])->whereNumber('id')->name('print');
            Route::get('{type}/{id}/receipt', [InvoiceReceiptController::class, 'show'])
                ->whereIn('type', ['product', 'service'])->whereNumber('id')->name('receipt');
        });
    });

    Route::middleware('role:branch-admin|super-admin')->group(function () {
        Route::resource('agent-payments', AgentPaymentController::class)
            ->only(['index', 'store']);

        Route::patch('users/{user}/toggle-status', [UserController::class, 'toggleStatus'])
            ->name('users.toggle-status');
        Route::resource('users', UserController::class)
            ->only(['index', 'show', 'store', 'update', 'destroy']);

        Route::get('app-settings', [AppSettingController::class, 'index'])->name('app-settings.index');
        Route::put('app-settings/general', [AppSettingController::class, 'updateGeneral'])->name('app-settings.update-general');
        Route::put('app-settings/inventory-alerts', [AppSettingController::class, 'updateInventoryAlerts'])->name('app-settings.update-inventory-alerts');
        Route::put('app-settings/payment-methods', [AppSettingController::class, 'updatePaymentMethods'])->name('app-settings.update-payment-methods');
        Route::put('app-settings/loyalty', [AppSettingController::class, 'updateLoyalty'])->name('app-settings.update-loyalty');

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
        });
    });

    Route::get('coupons/validate', [CouponController::class, 'validateCoupon'])
        ->name('coupons.validate');

});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
