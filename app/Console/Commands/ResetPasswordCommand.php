<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Auth\Sessions;
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
 *
 * D-107 — it is also the admin's door while the SMS gateway is down. An admin changes a
 * password only with an SMS code, the forced first change included, so a reset that sets
 * `must_change_password` would drop the admin on a screen they cannot finish.
 * `--no-change-required` leaves the flag off; the admin changes the password by SMS once
 * the gateway delivers. An admin is found by email as well as by phone.
 *
 * D-120 — and every session the account held ends with the old password, access and
 * refresh tokens alike, legacy ones included. A password replaced from a terminal is
 * replaced because the old one is lost or leaked; a session made with it is exactly
 * what must not survive.
 */
class ResetPasswordCommand extends Command
{
    protected $signature = 'cr7:reset-password
        {login : phone, or an admin\'s email}
        {--password= : use this instead of the configured first password}
        {--no-change-required : do not force a change at first sign-in — for an admin while SMS cannot be delivered}';

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
            // The holder must replace it on first use — this password has been on a screen —
            // unless the replacing is what cannot be done yet (D-107, see above).
            'must_change_password' => ! $this->option('no-change-required'),
        ])->save();

        Sessions::revokeAll($user);

        $this->info("{$user->name} · {$user->type->value} · {$user->phone}");
        $this->line('every session this account held is signed out');
        $this->line('new password: '.$password);

        if (TempPassword::isShared()) {
            $this->warn('This is the shared first password. Anyone who knows the number can sign in until the holder changes it.');
        }

        return self::SUCCESS;
    }
}
