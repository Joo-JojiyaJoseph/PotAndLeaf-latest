<?php

/** A server that has not run the Request access migration keeps working. */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

it('keeps role permissions working and explains what is missing', function () {
    $this->createCompanyWithUser(['sales.view']);
    Schema::drop('user_permissions');

    expect($this->user->fresh()->hasPermission('sales.view', $this->company->id))->toBeTrue();

    $form = $this->getJson('/api/access-requests/form-data', $this->apiHeaders())->assertOk()->json('data');
    expect($form['ready'])->toBeFalse()->and($form['groups'])->not->toBeEmpty();

    $this->getJson('/api/access-requests/notices', $this->apiHeaders())->assertOk()->assertJsonPath('data.waiting_for_me', 0);
    $this->postJson('/api/access-requests', ['permissions' => ['reports.view'], 'reason' => 'need it'], $this->apiHeaders())
        ->assertStatus(503)->assertJsonFragment(['message' => 'Request access is not set up on the server yet. Ask the person who looks after the server to run "php artisan migrate".']);
});
