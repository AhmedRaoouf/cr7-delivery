<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\PhoneNumber;
use App\Support\TempPassword;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * The recovery path for an account whose first password was never seen by anyone.
 *
 * Every captain created before `TempPassword` landed was given `Str::password(12)` and
 * handed to a dashboard that dropped it while reading the response — the account exists,
 * is active, and nobody alive knows what opens it. The admin UI can reset one now, but a
 * locked-out operator cannot always reach the admin UI, and this needs no browser.
 *
 * It prints the new password. That is the point of it, so run it where the output is not
 * being recorded, and never paste the result into a chat or a ticket.
 */
class ResetPasswordCommand extends Command
{
    protected $signature = 'cr7:reset-password {login : phone or email} {--password= : use this instead of the configured first password}';

    protected $description = 'Reset one account password to the configured first password and print it';

    public function handle(): int
    {
        $login = (string) $this->argument('login');

        $user = User::query()->whereLogin($login)->first();

        if (! $user) {
            $this->error("No account matches «{$login}» (looked for ".PhoneNumber::normaliseLogin($login).').');

            return self::FAILURE;
        }

        $password = ((string) ($this->option('password') ?? '')) ?: TempPassword::issue();

        $user->forceFill([
            'password' => Hash::make($password),
            // The holder must replace it on first use — this password has been on a screen.
            'must_change_password' => true,
        ])->save();

        $this->info("{$user->name} · {$user->type->value} · {$user->phone}");
        $this->line('new password: '.$password);

        if (TempPassword::isShared()) {
            $this->warn('This is the shared first password. Anyone who knows the number can sign in until the holder changes it.');
        }

        return self::SUCCESS;
    }
}
