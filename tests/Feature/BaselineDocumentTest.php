<?php

use App\Enums\BaselineStatus;
use App\Enums\UserRole;
use App\Models\Baseline;
use App\Models\BaselineDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('a contract document is stored privately on the draft baseline', function () {
    Storage::fake('local');

    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $baseline = Baseline::factory()->for($manager->organization)->create();

    $this->actingAs($manager)
        ->post(route('baselines.documents.store', $baseline), [
            'document' => UploadedFile::fake()->create('acme-sow.pdf', 512, 'application/pdf'),
        ])
        ->assertRedirect(route('engagements.baseline.show', $baseline->engagement_id));

    $document = BaselineDocument::query()->sole();

    expect($document->filename)->toBe('acme-sow.pdf')
        ->and($document->baseline_id)->toBe($baseline->id)
        ->and($document->uploaded_by)->toBe($manager->id)
        ->and($document->path)->toStartWith("baselines/{$baseline->id}/contracts/");

    Storage::disk('local')->assertExists($document->path);
});

test('a contract document downloads with its original filename', function () {
    Storage::fake('local');

    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $baseline = Baseline::factory()->for($manager->organization)->create();

    $this->actingAs($manager)->post(route('baselines.documents.store', $baseline), [
        'document' => UploadedFile::fake()->create('annex-b.pdf', 100, 'application/pdf'),
    ]);

    $document = BaselineDocument::query()->sole();

    $this->actingAs($manager)
        ->get(route('baselines.documents.show', [$baseline, $document]))
        ->assertSuccessful()
        ->assertDownload('annex-b.pdf');
});

test('removing a contract document deletes the stored file', function () {
    Storage::fake('local');

    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $baseline = Baseline::factory()->for($manager->organization)->create();

    $this->actingAs($manager)->post(route('baselines.documents.store', $baseline), [
        'document' => UploadedFile::fake()->create('draft-sow.pdf', 100, 'application/pdf'),
    ]);

    $document = BaselineDocument::query()->sole();

    $this->actingAs($manager)
        ->delete(route('baselines.documents.destroy', [$baseline, $document]))
        ->assertRedirect(route('engagements.baseline.show', $baseline->engagement_id));

    expect(BaselineDocument::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing($document->path);
});

test('only contract-like file types are accepted', function () {
    Storage::fake('local');

    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $baseline = Baseline::factory()->for($manager->organization)->create();

    $this->actingAs($manager)
        ->post(route('baselines.documents.store', $baseline), [
            'document' => UploadedFile::fake()->create('backup.zip', 100, 'application/zip'),
        ])
        ->assertInvalid(['document']);
});

test('the contract set is frozen once the baseline is submitted', function () {
    Storage::fake('local');

    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $baseline = Baseline::factory()->for($manager->organization)->status(BaselineStatus::AwaitingApproval)->create();

    $this->actingAs($manager)
        ->post(route('baselines.documents.store', $baseline), [
            'document' => UploadedFile::fake()->create('late-annex.pdf', 100, 'application/pdf'),
        ])
        ->assertForbidden();
});

test('members cannot manage contract documents', function () {
    Storage::fake('local');

    $member = User::factory()->role(UserRole::Member)->create();
    $baseline = Baseline::factory()->for($member->organization)->create();

    $this->actingAs($member)
        ->post(route('baselines.documents.store', $baseline), [
            'document' => UploadedFile::fake()->create('sow.pdf', 100, 'application/pdf'),
        ])
        ->assertForbidden();
});

test('documents of another organization are not reachable', function () {
    Storage::fake('local');

    $document = BaselineDocument::factory()->create();
    $outsider = User::factory()->role(UserRole::DeliveryManager)->create();

    $this->actingAs($outsider)
        ->get(route('baselines.documents.show', [$document->baseline_id, $document]))
        ->assertNotFound();
});
