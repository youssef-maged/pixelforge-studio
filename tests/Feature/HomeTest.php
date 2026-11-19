<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows guests the landing page', function () {
    $this->get('/')->assertOk()->assertInertia(fn ($page) => $page->component('Welcome'));
});

it('sends signed-in users straight to the studio', function () {
    $this->actingAs(User::factory()->create())->get('/')->assertRedirect('/studio');
});
