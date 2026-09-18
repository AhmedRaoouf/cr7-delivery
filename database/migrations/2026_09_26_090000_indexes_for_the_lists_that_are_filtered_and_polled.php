<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 68 — the indexes the lists read by (ADMIN-LISTS.md).
 *
 * Every list now pages newest-first with an `id` tiebreak, and the heaviest ones are
 * polled every fifteen to twenty seconds by every open board. Each index below serves a
 * query whose plan was a full scan or a filesort over the whole table (checked with
 * `EXPLAIN` against a copy of the schema filled to 200 000 orders):
 *
 *  - `orders (created_at)` — `ADM-04` with no filter, a global admin's default view.
 *    The existing `(status, created_at)` cannot order without a status.
 *  - `orders (restaurant_id, created_at)` — a place's history (`RES-10`, no queue).
 *    `(restaurant_id, status, created_at)` orders only within one status.
 *  - `users (type, created_at)` — `ADM-13`, customers newest first.
 *  - `notifications (user_id, created_at)` — every account's feed. The existing
 *    `(user_id, read_at, created_at)` serves the unread count, not a feed that mixes
 *    read and unread rows; the feed was a filesort of the account's whole history.
 *  - `payments (created_at)`, `complaints (created_at)`, `audit_logs (created_at)`,
 *    `audit_logs (action, created_at)` — the default order of their lists, and the
 *    audit log's action filter.
 *  - `ratings (rateable_type, rateable_id, is_hidden, created_at)` — a place's public
 *    reviews and `RES-27`, newest first.
 *
 * Guarded by `hasIndex`, so a database that already carries one (added by hand during an
 * incident) migrates cleanly.
 */
return new class extends Migration
{
    /** @var array<string, array<string, list<string>>> table → index name → columns */
    private const INDEXES = [
        'orders' => [
            'orders_created_at_index' => ['created_at'],
            'orders_restaurant_id_created_at_index' => ['restaurant_id', 'created_at'],
        ],
        'users' => [
            'users_type_created_at_index' => ['type', 'created_at'],
        ],
        'notifications' => [
            'notifications_user_id_created_at_index' => ['user_id', 'created_at'],
        ],
        'payments' => [
            'payments_created_at_index' => ['created_at'],
        ],
        'complaints' => [
            'complaints_created_at_index' => ['created_at'],
        ],
        'audit_logs' => [
            'audit_logs_created_at_index' => ['created_at'],
            'audit_logs_action_created_at_index' => ['action', 'created_at'],
        ],
        'ratings' => [
            'ratings_rateable_hidden_created_at_index' => ['rateable_type', 'rateable_id', 'is_hidden', 'created_at'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                if (Schema::hasIndex($table, $name)) {
                    continue;
                }

                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                if (Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
                }
            }
        }
    }
};
