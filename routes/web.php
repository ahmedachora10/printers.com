<?php

use App\Http\Controllers\BranchController;
use App\Http\Controllers\BranchServiceController;
use App\Http\Controllers\CityController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ServiceTemplateController;
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
    });

    Route::middleware('role:branch-admin|super-admin')->group(function () {
        Route::resource('branch-services', BranchServiceController::class)
            ->only(['index', 'store', 'update', 'destroy']);

        Route::patch('product-categories/{productCategory}/toggle-status', [ProductCategoryController::class, 'toggleStatus'])
            ->name('product-categories.toggle-status');
        Route::resource('product-categories', ProductCategoryController::class)
            ->parameters(['product-categories' => 'productCategory'])
            ->only(['index', 'store', 'update', 'destroy']);
    });

});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
