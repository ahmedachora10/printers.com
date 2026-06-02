<?php

use App\Http\Controllers\AppSettingController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\BranchServiceController;
use App\Http\Controllers\CityController;
use App\Http\Controllers\CouponController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductInvoiceController;
use App\Http\Controllers\ServiceInvoiceController;
use App\Http\Controllers\ServiceTemplateController;
use App\Http\Controllers\StockMovementController;
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
    });

    Route::middleware('role:branch-admin|super-admin|employee')->group(function () {
        Route::prefix('pos')->name('pos.')->group(function () {
            Route::get('service', [ServiceInvoiceController::class, 'create'])->name('service.create');
            Route::post('service', [ServiceInvoiceController::class, 'store'])->name('service.store');
            Route::get('service/{invoice}/print', [ServiceInvoiceController::class, 'print'])->name('service.print');
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
    });

    Route::middleware('role:branch-admin|super-admin')->group(function () {
        Route::patch('users/{user}/toggle-status', [UserController::class, 'toggleStatus'])
            ->name('users.toggle-status');
        Route::resource('users', UserController::class)
            ->only(['index', 'store', 'update', 'destroy']);

        Route::get('app-settings', [AppSettingController::class, 'index'])->name('app-settings.index');
        Route::put('app-settings/general', [AppSettingController::class, 'updateGeneral'])->name('app-settings.update-general');
        Route::put('app-settings/inventory-alerts', [AppSettingController::class, 'updateInventoryAlerts'])->name('app-settings.update-inventory-alerts');
        Route::put('app-settings/payment-methods', [AppSettingController::class, 'updatePaymentMethods'])->name('app-settings.update-payment-methods');

        Route::resource('branch-services', BranchServiceController::class)
            ->only(['index', 'store', 'update', 'destroy']);

        Route::patch('product-categories/{productCategory}/toggle-status', [ProductCategoryController::class, 'toggleStatus'])
            ->name('product-categories.toggle-status');
        Route::resource('product-categories', ProductCategoryController::class)
            ->parameters(['product-categories' => 'productCategory'])
            ->only(['index', 'store', 'update', 'destroy']);

        Route::patch('coupons/{coupon}/toggle-status', [CouponController::class, 'toggleStatus'])
            ->name('coupons.toggle-status');
        Route::resource('coupons', CouponController::class)
            ->only(['index', 'store', 'update', 'destroy']);

        Route::prefix('inventory')->name('inventory.')->group(function () {
            Route::patch('products/{product}/toggle-status', [ProductController::class, 'toggleStatus'])
                ->name('products.toggle-status');
            Route::resource('products', ProductController::class)
                ->only(['index', 'store', 'update', 'destroy']);

            Route::post('stock-movements', [StockMovementController::class, 'store'])
                ->name('stock-movements.store');
        });
    });

    Route::get('coupons/validate', [CouponController::class, 'validateCoupon'])
        ->name('coupons.validate');

});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
