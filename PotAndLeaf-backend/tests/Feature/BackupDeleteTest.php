<?php

/** Deleting a saved backup file from the Backups screen. */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    Storage::fake('local');
    Storage::disk('local')->put('backups/backup_20261026_141704_manual.sqlite', 'a');
    Storage::disk('local')->put('backups/backup_20261026_144345_pre-restore.sqlite', 'b');
});

it('deletes a backup file, but never the last one', function () {
    $this->createCompanyWithUser(['backup.view', 'backup.delete']);
    $h = $this->apiHeaders();

    $this->deleteJson('/api/backups/backup_20261026_141704_manual.sqlite', [], $h)->assertOk()->assertJsonPath('message', 'Backup deleted.');
    Storage::disk('local')->assertMissing('backups/backup_20261026_141704_manual.sqlite');
    expect(collect($this->getJson('/api/backups', $h)->json('data.backups'))->pluck('filename')->all())
        ->toBe(['backup_20261026_144345_pre-restore.sqlite']);

    $this->deleteJson('/api/backups/backup_20261026_144345_pre-restore.sqlite', [], $h)->assertStatus(422);
    Storage::disk('local')->assertExists('backups/backup_20261026_144345_pre-restore.sqlite');
});

it('refuses a missing file, a file that is not a backup, and a user without permission', function () {
    $this->createCompanyWithUser(['backup.view', 'backup.delete']);
    $h = $this->apiHeaders();
    $this->deleteJson('/api/backups/backup_19990101_000000_manual.sqlite', [], $h)->assertNotFound();
    Storage::disk('local')->put('backups/notes.sqlite', 'x');
    $this->deleteJson('/api/backups/notes.sqlite', [], $h)->assertStatus(422);
    $this->deleteJson('/api/backups/..%2F.env', [], $h)->assertStatus(404);

    $this->createCompanyWithUser(['backup.view']);
    app('auth')->forgetGuards();
    $this->deleteJson('/api/backups/backup_20261026_141704_manual.sqlite', [], $this->apiHeaders())->assertForbidden();
    Storage::disk('local')->assertExists('backups/backup_20261026_141704_manual.sqlite');
});
