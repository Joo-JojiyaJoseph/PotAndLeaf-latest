<?php

use App\Http\Controllers\PermissionController;
use App\Http\Controllers\ProductBrandController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductUnitController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Nursery ERP routes  (team-scoped: /{current_team}/…)
|--------------------------------------------------------------------------
| require this file from routes/web.php:  require __DIR__.'/nursery.php';
*/

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        // Suppliers (rich module)
        Route::resource('suppliers', SupplierController::class);
        Route::put('suppliers/{supplier}/restore', [SupplierController::class, 'restore'])
            ->withTrashed()->name('suppliers.restore');

        // Products (rich module)
        Route::resource('products', ProductController::class);
        Route::put('products/{product}/restore', [ProductController::class, 'restore'])
            ->withTrashed()->name('products.restore');

        // Roles (rich module) + permission catalog
        Route::resource('roles', RoleController::class)->except(['show']);
        Route::put('roles/{role}/restore', [RoleController::class, 'restore'])
            ->withTrashed()->name('roles.restore');
        Route::get('permissions', [PermissionController::class, 'index'])->name('permissions.index');

        // Users: list team members + assign ERP roles
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::put('users/{user}/roles', [UserController::class, 'updateRoles'])->name('users.roles.update');

        // Lookup masters (shared engine, inline modal CRUD — no create/edit pages)
        foreach ([
            'categories' => ProductCategoryController::class,
            'brands'     => ProductBrandController::class,
            'units'      => ProductUnitController::class,
        ] as $slug => $controller) {
            Route::get($slug, [$controller, 'index'])->name("{$slug}.index");
            Route::post($slug, [$controller, 'store'])->name("{$slug}.store");
            Route::put("{$slug}/{record}", [$controller, 'update'])->name("{$slug}.update");
            Route::delete("{$slug}/{record}", [$controller, 'destroy'])->name("{$slug}.destroy");
            Route::put("{$slug}/{record}/restore", [$controller, 'restore'])->name("{$slug}.restore");
        }
    });
