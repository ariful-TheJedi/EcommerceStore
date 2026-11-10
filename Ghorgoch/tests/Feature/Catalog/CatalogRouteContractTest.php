<?php

declare(strict_types=1);

/**
 * Catalog route contract tests.
 *
 * Place at tests/Feature/Catalog/CatalogRouteContractTest.php
 * Run: php artisan test tests/Feature/Catalog/CatalogRouteContractTest.php
 *
 * These tests intentionally inspect the route definitions rather than exercising
 * controllers. Functional, authentication, and database-security behavior belongs
 * in the relevant Catalog feature tests.
 */

use App\Catalog\Controllers\FrontPageController;
use App\Catalog\Controllers\InventoryController;
use App\Catalog\Controllers\MediaController;
use App\Catalog\Controllers\ProductController;
use App\Catalog\Controllers\TaxonomyController;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

/** @return array<string, array{method: string, path: string, action: string, permission: string|null, defaults?: array<string, string>, parameters?: array<string, string>}> */
function catalogRouteContracts(): array
{
    return [
        // Public storefront.
        'catalog.public.front-page' => ['method' => 'GET', 'path' => 'catalog/front-page', 'action' => FrontPageController::class.'@publicShow', 'permission' => null],
        'catalog.public.categories.index' => ['method' => 'GET', 'path' => 'catalog/categories', 'action' => TaxonomyController::class.'@publicIndex', 'permission' => null],
        'catalog.public.categories.show' => ['method' => 'GET', 'path' => 'catalog/categories/{slug}', 'action' => TaxonomyController::class.'@publicShow', 'permission' => null],
        'catalog.public.products.index' => ['method' => 'GET', 'path' => 'catalog/products', 'action' => ProductController::class.'@publicIndex', 'permission' => null],
        'catalog.public.products.show' => ['method' => 'GET', 'path' => 'catalog/products/{product}', 'action' => ProductController::class.'@publicShow', 'permission' => null, 'parameters' => ['product' => '[0-9]+']],

        'catalog.categories.image' => ['method' => 'POST', 'path' => 'categories/{item}/image', 'action' => TaxonomyController::class.'@uploadCategoryImage', 'permission' => 'catalog.manage', 'defaults' => ['catalog_kind' => 'category'], 'parameters' => ['item' => '[0-9]+']],
        'catalog.categories.image.destroy' => ['method' => 'DELETE', 'path' => 'categories/{item}/image', 'action' => TaxonomyController::class.'@removeCategoryImage', 'permission' => 'catalog.manage', 'defaults' => ['catalog_kind' => 'category'], 'parameters' => ['item' => '[0-9]+']],

        // Staff products and variants.
        'catalog.products.index' => ['method' => 'GET', 'path' => 'products', 'action' => ProductController::class.'@index', 'permission' => 'catalog.view'],
        'catalog.products.store' => ['method' => 'POST', 'path' => 'products', 'action' => ProductController::class.'@store', 'permission' => 'catalog.manage'],
        'catalog.products.show' => ['method' => 'GET', 'path' => 'products/{product}', 'action' => ProductController::class.'@show', 'permission' => 'catalog.view', 'parameters' => ['product' => '[0-9]+']],
        'catalog.products.update' => ['method' => 'PATCH', 'path' => 'products/{product}', 'action' => ProductController::class.'@update', 'permission' => 'catalog.manage', 'parameters' => ['product' => '[0-9]+']],
        'catalog.products.archive' => ['method' => 'POST', 'path' => 'products/{product}/archive', 'action' => ProductController::class.'@archive', 'permission' => 'catalog.manage', 'parameters' => ['product' => '[0-9]+']],
        'catalog.products.variants.store' => ['method' => 'POST', 'path' => 'products/{product}/variants', 'action' => ProductController::class.'@storeVariant', 'permission' => 'catalog.manage', 'parameters' => ['product' => '[0-9]+']],
        'catalog.products.variants.update' => ['method' => 'PATCH', 'path' => 'products/{product}/variants/{variant}', 'action' => ProductController::class.'@updateVariant', 'permission' => 'catalog.manage', 'parameters' => ['product' => '[0-9]+', 'variant' => '[0-9]+']],
        'catalog.products.variants.disable' => ['method' => 'POST', 'path' => 'products/{product}/variants/{variant}/disable', 'action' => ProductController::class.'@disableVariant', 'permission' => 'catalog.manage', 'parameters' => ['product' => '[0-9]+', 'variant' => '[0-9]+']],

        // Product media.
        'catalog.products.images.store' => ['method' => 'POST', 'path' => 'products/{product}/images', 'action' => MediaController::class.'@upload', 'permission' => 'catalog.manage', 'parameters' => ['product' => '[0-9]+']],
        'catalog.products.images.update' => ['method' => 'PATCH', 'path' => 'products/{product}/images/{image}', 'action' => MediaController::class.'@updateImage', 'permission' => 'catalog.manage', 'parameters' => ['product' => '[0-9]+', 'image' => '[0-9]+']],
        'catalog.products.images.destroy' => ['method' => 'DELETE', 'path' => 'products/{product}/images/{image}', 'action' => MediaController::class.'@destroy', 'permission' => 'catalog.manage', 'parameters' => ['product' => '[0-9]+', 'image' => '[0-9]+']],

        // Hierarchical categories and non-hierarchical tags.
        'catalog.categories.index' => ['method' => 'GET', 'path' => 'categories', 'action' => TaxonomyController::class.'@index', 'permission' => 'catalog.view', 'defaults' => ['catalog_kind' => 'category']],
        'catalog.categories.store' => ['method' => 'POST', 'path' => 'categories', 'action' => TaxonomyController::class.'@store', 'permission' => 'catalog.manage', 'defaults' => ['catalog_kind' => 'category']],
        'catalog.categories.show' => ['method' => 'GET', 'path' => 'categories/{item}', 'action' => TaxonomyController::class.'@show', 'permission' => 'catalog.view', 'defaults' => ['catalog_kind' => 'category'], 'parameters' => ['item' => '[0-9]+']],
        'catalog.categories.update' => ['method' => 'PATCH', 'path' => 'categories/{item}', 'action' => TaxonomyController::class.'@update', 'permission' => 'catalog.manage', 'defaults' => ['catalog_kind' => 'category'], 'parameters' => ['item' => '[0-9]+']],
        'catalog.categories.destroy' => ['method' => 'DELETE', 'path' => 'categories/{item}', 'action' => TaxonomyController::class.'@destroy', 'permission' => 'catalog.manage', 'defaults' => ['catalog_kind' => 'category'], 'parameters' => ['item' => '[0-9]+']],
        'catalog.tags.index' => ['method' => 'GET', 'path' => 'tags', 'action' => TaxonomyController::class.'@index', 'permission' => 'catalog.view', 'defaults' => ['catalog_kind' => 'tag']],
        'catalog.tags.store' => ['method' => 'POST', 'path' => 'tags', 'action' => TaxonomyController::class.'@store', 'permission' => 'catalog.manage', 'defaults' => ['catalog_kind' => 'tag']],
        'catalog.tags.show' => ['method' => 'GET', 'path' => 'tags/{item}', 'action' => TaxonomyController::class.'@show', 'permission' => 'catalog.view', 'defaults' => ['catalog_kind' => 'tag'], 'parameters' => ['item' => '[0-9]+']],
        'catalog.tags.update' => ['method' => 'PATCH', 'path' => 'tags/{item}', 'action' => TaxonomyController::class.'@update', 'permission' => 'catalog.manage', 'defaults' => ['catalog_kind' => 'tag'], 'parameters' => ['item' => '[0-9]+']],
        'catalog.tags.destroy' => ['method' => 'DELETE', 'path' => 'tags/{item}', 'action' => TaxonomyController::class.'@destroy', 'permission' => 'catalog.manage', 'defaults' => ['catalog_kind' => 'tag'], 'parameters' => ['item' => '[0-9]+']],

        // Inventory and order reservation actions.
        'catalog.inventory.index' => ['method' => 'GET', 'path' => 'inventory', 'action' => InventoryController::class.'@stock', 'permission' => 'catalog.view'],
        'catalog.inventory.history' => ['method' => 'GET', 'path' => 'inventory/{variant}/history', 'action' => InventoryController::class.'@history', 'permission' => 'catalog.view', 'parameters' => ['variant' => '[0-9]+']],
        'catalog.inventory.adjust' => ['method' => 'POST', 'path' => 'inventory/{variant}/adjust', 'action' => InventoryController::class.'@adjust', 'permission' => 'inventory.adjust', 'parameters' => ['variant' => '[0-9]+']],
        'catalog.inventory.reserve' => ['method' => 'POST', 'path' => 'inventory/{variant}/reserve', 'action' => InventoryController::class.'@reserve', 'permission' => 'orders.process', 'parameters' => ['variant' => '[0-9]+']],
        'catalog.inventory.release' => ['method' => 'POST', 'path' => 'inventory/{variant}/release', 'action' => InventoryController::class.'@release', 'permission' => 'orders.process', 'parameters' => ['variant' => '[0-9]+']],
        'catalog.inventory.deduct' => ['method' => 'POST', 'path' => 'inventory/{variant}/deduct', 'action' => InventoryController::class.'@deduct', 'permission' => 'orders.process', 'parameters' => ['variant' => '[0-9]+']],
    ];
}

function catalogPathMatches(string $actual, string $expected): bool
{
    // Routes may be mounted under /api or another application-level prefix.
    return $actual === $expected || str_ends_with($actual, '/'.$expected);
}

it('registers every named Catalog route with its expected method, path, action and access defaults', function (): void {
    $contracts = catalogRouteContracts();

    expect($contracts)->toHaveCount(34);

    foreach ($contracts as $name => $contract) {
        $route = Route::getRoutes()->getByName($name);

        expect($route)->not->toBeNull("Missing named route: {$name}");
        expect($route)->toBeInstanceOf(LaravelRoute::class);
        expect($route->methods())->toContain($contract['method']);
        expect(catalogPathMatches($route->uri(), $contract['path']))
            ->toBeTrue("Unexpected URI for {$name}: {$route->uri()}");
        expect($route->getActionName())->toBe($contract['action']);

        if ($contract['permission'] === null) {
            expect($route->defaults)->not->toHaveKey('staff_permission');
        } else {
            expect($route->defaults['staff_permission'] ?? null)->toBe($contract['permission']);
        }

        foreach ($contract['defaults'] ?? [] as $key => $value) {
            expect($route->defaults[$key] ?? null)->toBe($value);
        }

        foreach ($contract['parameters'] ?? [] as $parameter => $pattern) {
            expect($route->wheres[$parameter] ?? null)->toBe($pattern);
        }
    }
});

it('registers exactly the expected named Catalog endpoints', function (): void {
    $actual = [];

    foreach (Route::getRoutes() as $route) {
        $name = $route->getName();

        if ($name !== null && str_starts_with($name, 'catalog.')) {
            $actual[] = $name;
        }
    }

    sort($actual);
    $expected = array_keys(catalogRouteContracts());
    sort($expected);

    expect($actual)->toBe($expected);
});

it('registers the three GET health endpoints', function (): void {
    foreach (['products', 'categories', 'inventory'] as $module) {
        $matching = [];

        foreach (Route::getRoutes() as $route) {
            if (catalogPathMatches($route->uri(), $module.'/health') && in_array('GET', $route->methods(), true)) {
                $matching[] = $route;
            }
        }

        expect($matching)->toHaveCount(1, "Expected one GET route for {$module}/health");
        expect($matching[0]->getActionName())->toBe('Closure');
    }
});

it('has no duplicate HTTP method and URI registrations for Catalog endpoints', function (): void {
    $routes = [];

    foreach (Route::getRoutes() as $route) {
        $name = $route->getName();
        $isCatalog = $name !== null && str_starts_with($name, 'catalog.');
        $isHealth = in_array(true, array_map(
            fn (string $module): bool => catalogPathMatches($route->uri(), $module.'/health'),
            ['products', 'categories', 'inventory']
        ), true);

        if (! $isCatalog && ! $isHealth) {
            continue;
        }

        foreach ($route->methods() as $method) {
            $key = $method.' '.$route->uri();
            expect($routes)->not->toHaveKey($key, "Duplicate route: {$key}");
            $routes[$key] = true;
        }
    }

    expect($routes)->not->toBeEmpty();
});
