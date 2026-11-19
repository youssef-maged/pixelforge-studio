<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config(['filesystems.images' => 'local']);
    $this->user = User::factory()->create();
});

it('stores a real image privately and returns a link that expires in 7 days', function () {
    $response = $this->actingAs($this->user)
        ->post('/reference-images', ['image' => UploadedFile::fake()->image('style.png', 512, 512)], ['Accept' => 'application/json'])
        ->assertCreated();

    expect($response->json('url'))->toContain('expir')
        ->and(Storage::disk('local')->allFiles("references/{$this->user->id}"))->toHaveCount(1);
});

it('rejects files that are not usable images', function (UploadedFile $file, string $message) {
    $this->actingAs($this->user)
        ->post('/reference-images', ['image' => $file], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.image.0', $message);
})->with([
    'not an image' => [fn () => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'), "That file isn't an image we can read."],
    'too small' => [fn () => UploadedFile::fake()->image('tiny.png', 32, 32), 'Images must be between 64×64 and 8192×8192 pixels.'],
    'too large' => [fn () => UploadedFile::fake()->image('huge.jpg', 512, 512)->size(11_000), 'Images can be at most 10 MB.'],
    'gif' => [fn () => UploadedFile::fake()->image('anim.gif', 128, 128), 'Use a PNG, JPEG or WebP image.'],
]);

it('requires a signed-in user', function () {
    $this->post('/reference-images', ['image' => UploadedFile::fake()->image('a.png', 100, 100)], ['Accept' => 'application/json'])->assertUnauthorized();
});
