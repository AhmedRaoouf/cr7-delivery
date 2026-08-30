<?php

namespace App\Console\Commands;

use App\Models\CaptainApplication;
use App\Models\Complaint;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\Settlement;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Removes exactly what `DemoDataSeeder` writes, and nothing else.
 *
 * Every demo row is reachable from a key that starts with `DEMO`, or from a phone
 * number in the reserved `0100111…` / `0100222…` / `0100333…` blocks. The real
 * catalogue — restaurants, categories, products, the super admin — is never touched,
 * so this is safe to run against a database that also holds real data.
 */
class DemoFlushCommand extends Command
{
    protected $signature = 'demo:flush {--force : Skip the confirmation}';

    protected $description = 'Delete the demo customers, captains, orders, complaints and settlements';

    public function handle(): int
    {
        if (app()->isProduction() && $this->option('force') === false) {
            $this->error('Refusing to run on production without --force.');

            return self::FAILURE;
        }

        $groupIds = OrderGroup::query()->where('idempotency_key', 'like', 'DEMO-%')->pluck('id');
        $orderIds = Order::query()->whereIn('order_group_id', $groupIds)->pluck('id');

        $demoPhones = ['01001110%', '01002220%', '01003330%', '01000000077'];

        $userIds = User::query()
            ->where(function ($q) use ($demoPhones): void {
                foreach ($demoPhones as $pattern) {
                    $q->orWhere('phone', 'like', $pattern);
                }
            })
            ->pluck('id');

        DB::transaction(function () use ($groupIds, $orderIds, $userIds): void {
            // Children first — `settlement_items` and `order_items` have no cascade,
            // and `orders` is soft-deleted, so a plain delete would leave the row
            // behind and the unique POS-number index would still be occupied.
            // Read the settlement ids BEFORE deleting the lines that point at them, and
            // never widen the net to «settlements with no items» — that would take real
            // ones with it.
            $settlementIds = DB::table('settlement_items')->whereIn('order_id', $orderIds)->pluck('settlement_id')->unique();
            DB::table('settlement_items')->whereIn('order_id', $orderIds)->delete();
            Settlement::query()->whereIn('id', $settlementIds)->whereDoesntHave('items')->delete();

            DB::table('complaint_messages')
                ->whereIn('complaint_id', Complaint::query()->where('ticket_number', 'like', 'DEMO-%')->pluck('id'))
                ->delete();
            Complaint::query()->where('ticket_number', 'like', 'DEMO-%')->delete();

            DB::table('delivery_issues')->whereIn('order_id', $orderIds)->delete();
            DB::table('ratings')->whereIn('order_id', $orderIds)->delete();
            DB::table('order_status_history')->whereIn('order_id', $orderIds)->delete();
            DB::table('order_item_options')
                ->whereIn('order_item_id', DB::table('order_items')->whereIn('order_id', $orderIds)->pluck('id'))
                ->delete();
            DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
            DB::table('order_driver_attempts')->whereIn('order_id', $orderIds)->delete();
            DB::table('order_notes')->whereIn('order_id', $orderIds)->delete();
            DB::table('orders')->whereIn('id', $orderIds)->delete();
            DB::table('order_groups')->whereIn('id', $groupIds)->delete();

            CaptainApplication::query()->where('reference', 'like', 'DEMO-%')->delete();

            DB::table('driver_locations')
                ->whereIn('driver_id', Driver::query()->whereIn('user_id', $userIds)->pluck('id'))
                ->delete();
            DB::table('drivers')->whereIn('user_id', $userIds)->delete();
            DB::table('user_addresses')->whereIn('user_id', $userIds)->delete();
            DB::table('model_has_roles')->whereIn('model_id', $userIds)->where('model_type', User::class)->delete();
            DB::table('admin_scopes')->whereIn('user_id', $userIds)->delete();
            DB::table('admin_profiles')->whereIn('user_id', $userIds)->delete();
            DB::table('personal_access_tokens')->whereIn('tokenable_id', $userIds)->where('tokenable_type', User::class)->delete();
            DB::table('users')->whereIn('id', $userIds)->update(['default_address_id' => null]);
            DB::table('users')->whereIn('id', $userIds)->delete();
        });

        $this->info('Flushed '.$orderIds->count().' demo orders and '.$userIds->count().' demo accounts.');

        return self::SUCCESS;
    }
}
