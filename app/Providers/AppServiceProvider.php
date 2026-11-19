<?php

namespace App\Providers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Our own limits, so one user or script can't burn the shared Pixelforge allowance.
        RateLimiter::for('generations', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('purchases', fn (Request $request) => Limit::perMinute(5)->by($request->user()?->id ?: $request->ip()));

        Gate::define('view-admin-report', fn (User $user) => $user->is_admin);

        if ($this->app->isLocal()) {
            // `composer run dev` also runs the scheduler (the missed-webhook safety net) and a public tunnel,
            // because Pixelforge only delivers webhooks to public URLs.
            DevCommands::artisan('schedule:work', 'scheduler');
            DevCommands::register('cloudflared tunnel --no-autoupdate --url http://localhost:'.config('app.dev_port'), 'tunnel');
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
