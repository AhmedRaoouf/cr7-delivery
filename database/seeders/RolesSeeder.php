<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/** doc 12 §2 and §4 — the 5 seeded, `is_system` admin roles and their permission matrix. */
class RolesSeeder extends Seeder
{
    public function run(): void
    {
        $matrix = [
            'super_admin' => Permission::query()->pluck('name')->all(),

            'operations' => [
                'orders.view', 'orders.view_all', 'orders.cancel', 'orders.reassign_driver', 'orders.redispatch',
                'orders.unassign_driver', 'orders.renotify', 'orders.extend_prep', 'orders.raise_issue', 'orders.note',
                'orders.resolve_issue', 'orders.cancel_sub_order', 'orders.recalculate_group',
                'restaurants.view', 'restaurants.create', 'restaurants.update', 'restaurants.toggle_status',
                'restaurants.reset_password', 'restaurants.manage_menu', 'restaurants.toggle_direct_orders',
                'direct_orders.view', 'direct_orders.view_all', 'direct_orders.cancel', 'direct_orders.manage_limits',
                'driver_applications.view', 'driver_applications.review',
                'products.view', 'products.create', 'products.update', 'products.delete', 'products.toggle_availability',
                'drivers.view', 'drivers.create', 'drivers.update', 'drivers.toggle_status',
                'drivers.reset_password', 'drivers.review_documents', 'drivers.view_location', 'fleet.view',
                'customers.view', 'customers.update', 'customers.toggle_status',
                'locations.view', 'locations.manage_cities', 'locations.manage_zones', 'locations.manage_pricing',
                'promos.view', 'promos.manage', 'banners.manage', 'content.manage', 'notifications.broadcast',
                'payments.view', 'refunds.view',
                'complaints.view', 'complaints.reply', 'complaints.update', 'complaints.assign',
                'reports.sales', 'reports.restaurants', 'reports.products', 'reports.drivers', 'reports.zones',
                'reports.export', 'reports.customers', 'ops.health',
                'media.view', 'media.manage',
            ],

            'finance' => [
                'orders.view', 'orders.view_all',
                'restaurants.view', 'restaurants.set_commission',
                'drivers.view', 'drivers.view_location', 'drivers.set_earning_rule', 'drivers.adjust_balance',
                'customers.view',
                'payments.view', 'payments.verify',
                'refunds.view', 'refunds.create', 'refunds.process',
                'settlements.view', 'settlements.generate', 'settlements.pay', 'commissions.view',
                'reports.sales', 'reports.restaurants', 'reports.products', 'reports.drivers', 'reports.zones',
                'reports.export', 'reports.customers',
                'audit.view',
            ],

            'support' => [
                'orders.view', 'orders.view_all', 'orders.raise_issue', 'orders.note', 'orders.resolve_issue',
                'restaurants.view',
                'drivers.view', 'drivers.view_location', 'fleet.view',
                'customers.view',
                'payments.view', 'refunds.view',
                'complaints.view', 'complaints.reply', 'complaints.update', 'complaints.assign',
                'complaints.mirror', 'complaints.note_internal',
                'reports.sales', 'reports.restaurants', 'reports.products', 'reports.drivers', 'reports.zones',
                'ops.health',
            ],

            'viewer' => [
                'orders.view', 'orders.view_all',
                'restaurants.view',
                'drivers.view', 'drivers.view_location',
                'customers.view',
                'payments.view',
                'reports.sales', 'reports.restaurants', 'reports.products', 'reports.drivers', 'reports.zones',
                'reports.export', 'reports.customers', 'ops.health',
            ],
        ];

        $labels = [
            'super_admin' => ['كل الصلاحيات', 'Super Admin'],
            'operations' => ['العمليات', 'Operations'],
            'finance' => ['المالية', 'Finance'],
            'support' => ['الدعم', 'Support'],
            'viewer' => ['مشاهدة فقط', 'Viewer'],
        ];

        foreach ($matrix as $name => $permissions) {
            $role = Role::query()->firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['label_ar' => $labels[$name][0], 'label_en' => $labels[$name][1], 'is_system' => true],
            );

            $role->syncPermissions($permissions);
        }
    }
}
