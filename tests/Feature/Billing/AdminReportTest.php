<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function finished(User $user, string $completedAt, ?int $cost, array $state = []): Generation
{
    return Generation::factory()->for($user)->create([
        'status' => $cost === null ? GenerationStatus::Failed : GenerationStatus::Succeeded,
        'provider_id' => 'gen_'.Str::random(10),
        'hold_nano' => 0,
        'provider_cost_nano' => $cost,
        'charge_nano' => $cost === null ? null : intdiv($cost * 5 + 3, 4),
        'usage' => $cost === null ? null : ['input_text_tokens' => 1],
        'completed_at' => $completedAt,
        ...$state,
    ]);
}

function usageDay(string $date, int $generations, string $cost): array
{
    return ['date' => $date, 'generations' => $generations, 'input_text_tokens' => 0, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0, 'cost_usd' => $cost];
}

beforeEach(function () {
    $this->admin = User::factory()->create(['email' => 'ops@example.com']);
    $this->admin->forceFill(['is_admin' => true])->save();
    $this->ann = User::factory()->create(['name' => 'Ann', 'email' => 'ann@example.com']);
    $this->bob = User::factory()->create(['name' => 'Bob', 'email' => 'bob@example.com']);
});

it('is for operators only', function () {
    $this->actingAs($this->ann)->get('/admin/report')->assertForbidden();
});

it('totals charges, provider costs and profit per UTC completion day, matching Pixelforge', function () {
    finished($this->ann, '2026-11-04 10:00:00', 305_510_000);
    finished($this->bob, '2026-11-04 23:59:58', 64_597_500); // finished just before midnight UTC: still the 4th
    finished($this->ann, '2026-11-05 00:00:01', 100_000_000);
    finished($this->bob, '2026-11-05 08:00:00', null); // failed after acceptance: free, not billed
    finished($this->bob, '2026-11-05 09:00:00', null, ['provider_id' => null, 'error_code' => 'content_policy_violation']); // rejected

    Http::fake(['*/v1/usage*' => Http::response(['data' => [
        usageDay('2026-11-04', 2, '0.370107500'),
        usageDay('2026-11-05', 1, '0.100000001'), // Pixelforge says one nanodollar more
    ]])]);

    $this->actingAs($this->admin)->get('/admin/report?from=2026-11-04&to=2026-11-05')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Report')
            ->where('summary.charged', '0.587634375')
            ->where('summary.cost', '0.470107500')
            ->where('summary.profit', '0.117526875')
            ->where('summary.mismatched_days', 1)
            ->where('days.data.0.date', '2026-11-05')
            ->where('days.data.0.failed', 1)
            ->where('days.data.0.rejected', 1)
            ->where('days.data.0.match', false)
            ->where('days.data.0.delta', '-0.000000001')
            ->where('days.data.1.date', '2026-11-04')
            ->where('days.data.1.succeeded', 2)
            ->where('days.data.1.cost', '0.370107500')
            ->where('days.data.1.match', true));
});

it('breaks a day down by user and ranks users by spend', function () {
    finished($this->ann, '2026-11-04 10:00:00', 100_000_000);
    finished($this->ann, '2026-11-04 11:00:00', 100_000_000);
    finished($this->bob, '2026-11-04 12:00:00', 40_000_000);
    Http::fake(['*/v1/usage*' => Http::response(['data' => []])]);

    $this->actingAs($this->admin)->get('/admin/report?from=2026-11-04&to=2026-11-04&day=2026-11-04')
        ->assertInertia(fn ($page) => $page
            ->where('topUsers.0.name', 'Ann')
            ->reloadOnly('dayUsers', fn ($reload) => $reload
                ->where('dayUsers.data.0.name', 'Ann')
                ->where('dayUsers.data.0.charged', '0.250000000')
                ->where('dayUsers.data.1.profit', '0.010000000')));
});

it('says it could not verify when Pixelforge is unreachable', function () {
    finished($this->ann, now()->toDateTimeString(), 100_000_000);
    Http::fake(['*/v1/usage*' => Http::response(['error' => ['code' => 'overloaded']], 503)]);

    $this->actingAs($this->admin)->get('/admin/report')
        ->assertInertia(fn ($page) => $page->where('summary.verified', false)->where('summary.charged', '0.125000000'));
});

it('refuses ranges longer than Pixelforge reports', function () {
    $this->actingAs($this->admin)->get('/admin/report?from=2026-01-01&to=2026-06-01')->assertSessionHasErrors('from');
});

it('exports the current view as exact CSV', function () {
    finished($this->ann, '2026-11-04 10:00:00', 305_510_000);
    Http::fake(['*/v1/usage*' => Http::response(['data' => [usageDay('2026-11-04', 1, '0.305510000')]])]);

    $csv = $this->actingAs($this->admin)->get('/admin/report/export?from=2026-11-04&to=2026-11-04')->streamedContent();

    expect($csv)->toContain('2026-11-04,1,0,0,0.381887500,0.305510000,0.076377500,0.305510000,yes');
});
