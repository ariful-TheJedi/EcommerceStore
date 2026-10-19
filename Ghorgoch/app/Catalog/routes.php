<?php

use App\Catalog\Controllers\InventoryController;
use App\Catalog\Controllers\MediaController;
use App\Catalog\Controllers\ProductController;
use App\Catalog\Controllers\TaxonomyController;
use Illuminate\Support\Facades\Route;

foreach (['products', 'categories', 'inventory'] as $module) {
    Route::get($module.'/health', fn (): array => ['module' => $module]);
}

Route::prefix('catalog')->name('catalog.public.')->group(function (): void {
    Route::get('/products', [ProductController::class, 'publicIndex'])->name('products.index');
    Route::get('/products/{slug}', [ProductController::class, 'publicShow'])->name('products.show');
});

Route::prefix('products')->name('catalog.products.')->group(function (): void {
    Route::get('/', [ProductController::class, 'index'])->defaults('staff_permission', 'catalog.view')->name('index');
    Route::post('/', [ProductController::class, 'store'])->defaults('staff_permission', 'catalog.manage')->name('store');
    Route::get('/{product}', [ProductController::class, 'show'])->whereNumber('product')->defaults('staff_permission', 'catalog.view')->name('show');
    Route::patch('/{product}', [ProductController::class, 'update'])->whereNumber('product')->defaults('staff_permission', 'catalog.manage')->name('update');
    Route::post('/{product}/archive', [ProductController::class, 'archive'])->whereNumber('product')->defaults('staff_permission', 'catalog.manage')->name('archive');
    Route::post('/{product}/variants', [ProductController::class, 'storeVariant'])->whereNumber('product')->defaults('staff_permission', 'catalog.manage')->name('variants.store');
    Route::patch('/{product}/variants/{variant}', [ProductController::class, 'updateVariant'])->whereNumber(['product', 'variant'])->defaults('staff_permission', 'catalog.manage')->name('variants.update');
    Route::post('/{product}/variants/{variant}/disable', [ProductController::class, 'disableVariant'])->whereNumber(['product', 'variant'])->defaults('staff_permission', 'catalog.manage')->name('variants.disable');
    Route::post('/{product}/images', [MediaController::class, 'upload'])->whereNumber('product')->defaults('staff_permission', 'catalog.manage')->name('images.store');
    Route::patch('/{product}/images/{image}', [MediaController::class, 'updateImage'])->whereNumber(['product', 'image'])->defaults('staff_permission', 'catalog.manage')->name('images.update');
    Route::delete('/{product}/images/{image}', [MediaController::class, 'destroy'])->whereNumber(['product', 'image'])->defaults('staff_permission', 'catalog.manage')->name('images.destroy');
});

foreach (['categories' => 'category', 'tags' => 'tag'] as $prefix => $kind) {
    Route::prefix($prefix)->name('catalog.'.$prefix.'.')->group(function () use ($kind): void {
        foreach (['get' => 'index', 'post' => 'store'] as $method => $action) {
            Route::$method('/', [TaxonomyController::class, $action])->defaults('catalog_kind', $kind)
                ->defaults('staff_permission', $method === 'get' ? 'catalog.view' : 'catalog.manage')->name($action);
        }
        foreach (['get' => 'show', 'patch' => 'update', 'delete' => 'destroy'] as $method => $action) {
            Route::$method('/{item}', [TaxonomyController::class, $action])->whereNumber('item')->defaults('catalog_kind', $kind)
                ->defaults('staff_permission', $method === 'get' ? 'catalog.view' : 'catalog.manage')->name($action);
        }
    });
}

Route::prefix('inventory')->name('catalog.inventory.')->group(function (): void {
    Route::get('/', [InventoryController::class, 'stock'])->defaults('staff_permission', 'catalog.view')->name('index');
    Route::get('/{variant}/history', [InventoryController::class, 'history'])->whereNumber('variant')->defaults('staff_permission', 'catalog.view')->name('history');
    foreach (['adjust', 'reserve', 'release', 'deduct'] as $action) {
        Route::post('/{variant}/'.$action, [InventoryController::class, $action])->whereNumber('variant')
            ->defaults('staff_permission', $action === 'adjust' ? 'inventory.adjust' : 'orders.process')->name($action);
    }
});
