<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

/** doc 12 §3 — every permission string in the system, verbatim. */
class PermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $groups = [
            'orders' => [
                'orders.view', 'orders.view_all', 'orders.cancel', 'orders.reassign_driver',
                'orders.unassign_driver', 'orders.redispatch', 'orders.force_pickup',
                'orders.force_status', 'orders.resolve_issue', 'orders.cancel_sub_order',
                'orders.recalculate_group', 'orders.renotify', 'orders.extend_prep',
                'orders.raise_issue', 'orders.note',
            ],
            'restaurants' => [
                'restaurants.view', 'restaurants.create', 'restaurants.update', 'restaurants.toggle_status',
                'restaurants.set_commission', 'restaurants.reset_password', 'restaurants.manage_menu',
                'restaurants.toggle_direct_orders',
            ],
            'direct_orders' => [
                'direct_orders.view', 'direct_orders.view_all', 'direct_orders.cancel', 'direct_orders.manage_limits',
            ],
            'driver_applications' => [
                'driver_applications.view', 'driver_applications.review',
            ],
            'products' => [
                'products.view', 'products.create', 'products.update', 'products.delete', 'products.toggle_availability',
            ],
            'drivers' => [
                'drivers.view', 'drivers.create', 'drivers.update', 'drivers.toggle_status',
                'drivers.set_earning_rule', 'drivers.reset_password', 'drivers.review_documents',
                'drivers.view_location', 'drivers.adjust_balance', 'fleet.view',
            ],
            'customers' => [
                'customers.view', 'customers.update', 'customers.toggle_status',
            ],
            'locations' => [
                'locations.view', 'locations.manage_cities', 'locations.manage_zones', 'locations.manage_pricing',
            ],
            'marketing' => [
                'promos.view', 'promos.manage', 'banners.manage', 'content.manage', 'notifications.broadcast',
            ],
            'money' => [
                'payments.view', 'payments.verify', 'refunds.view', 'refunds.create', 'refunds.process',
                'settlements.view', 'settlements.generate', 'settlements.pay', 'commissions.view',
            ],
            'support' => [
                'complaints.view', 'complaints.reply', 'complaints.update', 'complaints.assign',
                'complaints.mirror', 'complaints.note_internal',
            ],
            'reports' => [
                'reports.sales', 'reports.restaurants', 'reports.products', 'reports.drivers',
                'reports.zones', 'reports.export', 'reports.customers', 'ops.health',
            ],
            'media' => [
                'media.view', 'media.manage',
            ],
            'system' => [
                'users.view', 'users.manage', 'users.invite', 'users.suspend',
                'roles.manage', 'roles.create', 'scope.manage', 'settings.view', 'settings.manage', 'audit.view',
            ],
        ];

        foreach ($groups as $group => $permissions) {
            foreach ($permissions as $name) {
                Permission::query()->firstOrCreate(
                    ['name' => $name, 'guard_name' => 'web'],
                    ['group' => $group],
                );
            }
        }
    }
}
