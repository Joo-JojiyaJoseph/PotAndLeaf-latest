<?php

use App\Models\RentalInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

it('has no way to delete a rent bill', function () {
    $deletes = collect(Route::getRoutes())->filter(fn ($r) => in_array('DELETE', $r->methods(), true) && str_contains($r->uri(), 'rental-invoices'));
    expect($deletes)->toBeEmpty();
    expect(method_exists(\App\Services\RentalService::class, 'deleteInvoice'))->toBeFalse();
});
