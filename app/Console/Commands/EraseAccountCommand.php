<?php

namespace App\Console\Commands;

use App\Actions\Auth\DeleteAccountAction;
use App\Enums\UserType;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\Auth\AccountErasure;
use App\Support\PhoneNumber;
use Illuminate\Console\Command;

/**
 * Batch 72 — the written-request path of «حذف الحساب».
 *
 * A customer who cannot open the app (a lost phone, an uninstalled app) asks in writing, the
 * office verifies the number, and this runs the **same** deletion the in-app button runs —
 * {@see AccountErasure} — so a request made by message erases exactly what a request made in
 * the app does. Customers only: a captain carries a wallet and documents, and a restaurant is
 * a business, so those are settled by hand (see the public «حذف الحساب» page).
 *
 * Refuses, like the API, while an order, an errand or a scheduled order is still open.
 */
class EraseAccountCommand extends Command
{
    protected $signature = 'cr7:erase-account
        {phone : the customer\'s phone, once the office has verified the request}
        {--force : do not ask for confirmation}';

    protected $description = 'Delete a customer account and erase its personal data (the written-request path of the public delete-account page)';

    public function handle(DeleteAccountAction $action): int
    {
        $phone = PhoneNumber::normaliseLogin((string) $this->argument('phone'));
        $user = User::query()->where('phone', $phone)->first();

        if ($user === null) {
            $this->error("No account has the phone {$phone}.");

            return self::FAILURE;
        }

        if ($user->type !== UserType::Customer) {
            $this->error("That account is a {$user->type->value}, not a customer. Captain and restaurant accounts are settled by hand.");

            return self::FAILURE;
        }

        $work = AccountErasure::activeWork($user);
        $this->line(sprintf('Account #%d — orders open: %d, errands open: %d, scheduled orders: %d', $user->id, $work['active_orders'], $work['active_errands'], $work['scheduled_orders']));

        if (! $this->option('force') && ! $this->confirm('Erase this account\'s personal data? This cannot be undone.')) {
            return self::FAILURE;
        }

        try {
            $action->execute($user, 'written_request');
        } catch (ApiException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Account #{$user->id} erased.");

        return self::SUCCESS;
    }
}
