<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('products.view');
    }

    public function view(User $user, Product $product): bool
    {
        return $user->hasPermission('products.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('products.create');
    }

    public function update(User $user, Product $product): bool
    {
        return $user->hasPermission('products.update');
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->hasPermission('products.delete');
    }

    public function restore(User $user, Product $product): bool
    {
        return $user->hasPermission('products.delete');
    }

    public function forceDelete(User $user, Product $product): bool
    {
        return $user->hasPermission('products.force-delete');
    }
}
