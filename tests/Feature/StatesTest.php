<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.pixelforge.key' => 'pf_live_SECRET_KEY_123',
        'services.pixelforge.webhook_secret' => 'whsec_SECRET_456',
    ]);
    Http::fake(['*/v1/usage*' => Http::response(['data' => []])]);
});

it('never sends secrets to the browser, on any page, for users or admins', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create();
    $admin->forceFill(['is_admin' => true])->save();

    foreach ([[$user, ['/studio', '/credits', '/settings/profile']], [$admin, ['/studio', '/credits', '/admin/report', '/admin/generations', '/admin/users', '/admin/webhooks']]] as [$who, $pages]) {
        foreach ($pages as $url) {
            $html = $this->actingAs($who)->get($url)->assertOk()->getContent();

            expect($html)
                ->not->toContain('pf_live_SECRET_KEY_123')
                ->not->toContain('whsec_SECRET_456')
                ->not->toContain((string) config('app.key'))
                ->not->toContain('two_factor_secret')
                ->not->toContain('"password"');
        }
    }
});

it('shows a friendly page when a normal user opens the admin area', function () {
    $this->actingAs(User::factory()->create())->get('/admin/report')
        ->assertForbidden()
        ->assertInertia(fn ($page) => $page->component('ErrorPage')->where('status', 403));
});

it('shows a friendly page for links that lead nowhere', function () {
    $this->actingAs(User::factory()->create())->get('/no-such-page')
        ->assertNotFound()
        ->assertInertia(fn ($page) => $page->component('ErrorPage')->where('status', 404));
});

it('sends guests to the login page when they open the app', function () {
    $this->get('/studio')->assertRedirect('/login');
});

it('explains an expired session instead of showing "419 Page Expired"', function () {
    Route::middleware('web')->post('/_expired', fn () => throw new TokenMismatchException);

    $this->post('/_expired')->assertRedirect('/login');
});

it('keeps every operator page and Horizon for admins', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create();
    $admin->forceFill(['is_admin' => true])->save();

    foreach (['/admin/report', '/admin/generations', '/admin/users', '/admin/webhooks'] as $url) {
        $this->actingAs($user)->get($url)->assertForbidden();
        $this->actingAs($admin)->get($url)->assertOk();
    }
    $this->actingAs($user)->get('/horizon')->assertForbidden();

    app()['env'] = 'local'; // Horizon's stock gate opens up for everyone in local; ours doesn't
    $this->actingAs($user)->get('/horizon')->assertForbidden();
});

it('filters the operator lists and validates their filters', function () {
    $admin = User::factory()->create();
    $admin->forceFill(['is_admin' => true])->save();

    $this->actingAs($admin)->get('/admin/generations?status=nonsense')->assertSessionHasErrors('status');
    $this->actingAs($admin)->get('/admin/users?q=nobody-here')
        ->assertInertia(fn ($page) => $page->component('Admin/Users')->where('users.data', []));
});

it('tells someone who was signed out mid-session why they are on the login page', function () {
    $this->post('/generations', [], ['X-Inertia' => 'true'])->assertRedirect('/login');

    expect(session('inertia.flash_data.toast.message') ?? collect(session()->all())->flatten()->first(fn ($v) => is_string($v) && str_contains($v, 'signed out')))
        ->toContain('signed out');
});
