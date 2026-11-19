<?php

namespace Database\Seeders;

use App\Actions\BuyCredits;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Accounts only. Generations are never seeded: reviewers compare our numbers with Pixelforge's own
 * records (GET /v1/usage), so every generation must be a real one made through the app.
 * Passwords come from DEMO_PASSWORD / ADMIN_PASSWORD, or are generated and printed once.
 */
class DatabaseSeeder extends Seeder
{
    public function run(BuyCredits $buy): void
    {
        $accounts = [
            ['name' => 'Demo User', 'email' => 'demo@pixelforge.test', 'password' => config('app.seed_passwords.demo'), 'admin' => false],
            ['name' => 'Reviewer', 'email' => 'reviewer@pixelforge.test', 'password' => config('app.seed_passwords.admin'), 'admin' => true],
        ];

        foreach ($accounts as $account) {
            if (User::where('email', $account['email'])->exists()) {
                continue;
            }

            $password = $account['password'] ?: Str::password(20);
            $user = User::create(['name' => $account['name'], 'email' => $account['email'], 'password' => $password]);
            $user->forceFill(['is_admin' => $account['admin']])->save();

            // The seeded demo user starts with $10, bought through the same ledger as everyone else.
            $buy($user, 10, (string) Str::uuid());

            if (! $account['password']) {
                $this->command->warn("{$account['email']} password: {$password}");
            }
        }
    }
}
