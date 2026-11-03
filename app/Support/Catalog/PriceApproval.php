<?php

namespace App\Support\Catalog;

use App\Enums\UserType;
use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductPriceRequest;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\AdminScope;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\SettingsCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * doc 99 §1 — D-169: a place's price change waits for the office.
 *
 * Owners' words: «السماح للتاجر بتعديل أسعار المنتجات من حسابه كالمعتاد على أن يتم إرسال
 * التعديلات إلى لوحة الإدارة لمراجعتها والموافقة عليها أولاً ولا تظهر الأسعار الجديدة
 * للعملاء إلا بعد اعتماد الإدارة».
 *
 * **What waits:** a place changing an *existing* dish's `price` / `discount_price`, or an
 * *existing* option's `price_delta`. A new dish, a new option, a name, a picture or the
 * availability go live as they always did, and an admin's own edit is never gated — the
 * admin is the approver. `catalog.price_approval_enabled` off puts the direct write back.
 *
 * **Nothing stores a pending price** but the request row. `LinePrice`, the cart, the quote
 * and checkout read the live columns, so a customer cannot meet a price the office has not
 * approved, and the approval is one Eloquent write — which bumps the catalogue cache,
 * recomputes the price band and is audited as the admin, like any admin edit.
 *
 * **A value equal to the live one is not a change.** A form re-saved with the live price
 * in its field (an older place app does exactly that on every save) leaves a pending
 * request as it is; a place takes a request back with `DELETE /restaurant/price-requests/{id}`.
 *
 * **One pending request per dish** (a unique generated column holds it). A second change
 * supersedes the first with a new row carrying the whole requested state: the earlier
 * option changes merged in, a newer value for the same field winning.
 */
final class PriceApproval
{
    public static function enabled(): bool
    {
        return SettingsCatalog::bool('catalog.price_approval_enabled');
    }

    /**
     * What a place's product PATCH asks to change in price, compared with the live row.
     * Null when it asks nothing (absent fields, or the live values sent back).
     *
     * @param  array<string, mixed>  $data  the validated request
     * @return array{price: ?float, discount: ?float, clears: bool, touches_discount: bool}|null
     */
    public static function productChange(Product $product, array $data): ?array
    {
        $price = null;
        $discount = null;
        $clears = false;
        $touchesDiscount = false;

        if (array_key_exists('price', $data) && $data['price'] !== null
            && ! self::same((float) $data['price'], (float) $product->price)) {
            $price = round((float) $data['price'], 2);
        }

        if (array_key_exists('discount_price', $data)) {
            $sent = $data['discount_price'] === null || $data['discount_price'] === '' ? null : round((float) $data['discount_price'], 2);
            $live = $product->discount_price === null ? null : (float) $product->discount_price;

            if ($sent === null && $live !== null) {
                $clears = true;
                $touchesDiscount = true;
            } elseif ($sent !== null && ($live === null || ! self::same($sent, $live))) {
                $discount = $sent;
                $touchesDiscount = true;
            }
        }

        if ($price === null && ! $touchesDiscount) {
            return null;
        }

        return ['price' => $price, 'discount' => $discount, 'clears' => $clears, 'touches_discount' => $touchesDiscount];
    }

    /**
     * The price and discount a PATCH would leave the dish asking for — the live row, then
     * the pending request (when approval is on), then this request's own fields. What
     * `discount_price < price` is checked against: a request that sends only one of the
     * two is checked against the other's real value, never against nothing.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: float, 1: ?float}
     */
    public static function effectivePair(Product $product, array $input): array
    {
        $price = (float) $product->price;
        $discount = $product->discount_price === null ? null : (float) $product->discount_price;

        if (self::enabled()) {
            $pending = $product->openPriceRequest()->first();

            if ($pending !== null) {
                $price = $pending->requested_price !== null ? (float) $pending->requested_price : $price;
                $discount = $pending->clears_discount ? null
                    : ($pending->requested_discount_price !== null ? (float) $pending->requested_discount_price : $discount);
            }
        }

        $change = self::productChange($product, $input);

        if ($change !== null) {
            $price = $change['price'] ?? $price;

            if ($change['touches_discount']) {
                $discount = $change['clears'] ? null : $change['discount'];
            }
        }

        return [$price, $discount];
    }

    /**
     * File (or supersede) the dish's pending request with these changes.
     *
     * @param  array{price: ?float, discount: ?float, clears: bool, touches_discount: bool}|null  $change
     * @param  array<int, float>  $options  option id => requested `price_delta`
     * @return array{request: ProductPriceRequest, outcome: string} outcome `created` or `unchanged`
     */
    public static function file(Product $product, User $by, ?array $change, array $options = [], ?string $note = null): array
    {
        [$request, $outcome, $isFirst] = DB::transaction(function () use ($product, $by, $change, $options, $note): array {
            /** @var Product $live */
            $live = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            $previous = ProductPriceRequest::query()
                ->where('product_id', $live->id)
                ->where('status', ProductPriceRequest::PENDING)
                ->lockForUpdate()
                ->first();

            $state = [
                'requested_price' => $previous?->requested_price === null ? null : (float) $previous->requested_price,
                'requested_discount_price' => $previous?->requested_discount_price === null ? null : (float) $previous->requested_discount_price,
                'clears_discount' => (bool) ($previous?->clears_discount ?? false),
                'option_changes' => collect($previous?->option_changes ?? [])->keyBy(fn ($row) => (int) $row['option_id'])->all(),
            ];

            if ($change !== null) {
                if ($change['price'] !== null) {
                    $state['requested_price'] = $change['price'];
                }

                if ($change['touches_discount']) {
                    $state['requested_discount_price'] = $change['clears'] ? null : $change['discount'];
                    $state['clears_discount'] = $change['clears'];
                }
            }

            foreach ($options as $optionId => $delta) {
                $option = ProductOption::query()->with('group')->find($optionId);

                if ($option === null || (int) $option->group?->product_id !== (int) $live->id) {
                    continue;
                }

                $state['option_changes'][(int) $optionId] = [
                    'option_id' => (int) $option->id,
                    'group_id' => (int) $option->product_option_group_id,
                    'option_name_ar' => $option->name_ar,
                    'option_name_en' => $option->name_en,
                    'group_name_ar' => $option->group?->name_ar,
                    'current_price_delta' => (float) $option->price_delta,
                    'requested_price_delta' => round((float) $delta, 2),
                ];
            }

            $state['option_changes'] = array_values($state['option_changes']);

            if ($previous !== null && self::sameState($previous, $state)) {
                return [$previous, 'unchanged', false];
            }

            $previous?->forceFill(['status' => ProductPriceRequest::SUPERSEDED])->save();

            $request = ProductPriceRequest::query()->create([
                'product_id' => $live->id,
                'restaurant_id' => $live->restaurant_id,
                'requested_by' => $by->id,
                'status' => ProductPriceRequest::PENDING,
                'current_price' => $live->price,
                'current_discount_price' => $live->discount_price,
                'requested_price' => $state['requested_price'],
                'requested_discount_price' => $state['requested_discount_price'],
                'clears_discount' => $state['clears_discount'],
                'option_changes' => $state['option_changes'] === [] ? null : $state['option_changes'],
                'note' => $note ?? $previous?->note,
            ]);

            $previous?->forceFill(['superseded_by' => $request->id])->save();

            return [$request, 'created', $previous === null];
        });

        // The office hears about a dish once, not once per keystroke: a superseding
        // request replaces the row in their queue, which they are already looking at.
        if ($isFirst) {
            self::tellOffice($request);
        }

        return ['request' => $request, 'outcome' => $outcome];
    }

    /** The office approves: the requested values become the live ones, in one locked write. */
    public static function approve(ProductPriceRequest $request, User $admin, ?string $note = null): ProductPriceRequest
    {
        $approved = DB::transaction(function () use ($request, $admin, $note): ProductPriceRequest {
            /** @var ProductPriceRequest $row */
            $row = ProductPriceRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            self::assertPending($row);

            /** @var Product|null $product */
            $product = Product::withTrashed()->whereKey($row->product_id)->lockForUpdate()->first();

            if ($product === null || $product->trashed()) {
                throw new ApiException('price_request_product_deleted', 'تم حذف هذا الصنف، ولا يمكن اعتماد سعره', 409);
            }

            $price = $row->requested_price !== null ? (float) $row->requested_price : (float) $product->price;
            $discount = $row->clears_discount ? null
                : ($row->requested_discount_price !== null ? (float) $row->requested_discount_price
                    : ($product->discount_price === null ? null : (float) $product->discount_price));

            if ($discount !== null && $discount >= $price) {
                throw new ApiException(
                    'price_request_conflict',
                    'سعر الخصم المطلوب لم يعد أقل من سعر الصنف — ارفض الطلب ليرسل المكان سعرًا جديدًا',
                    409,
                );
            }

            // Eloquent, never the query builder: the save is what bumps the catalogue
            // cache, recomputes the band and writes the admin's audit row.
            if ($row->touchesProductPrice()) {
                $product->price = $price;
                $product->discount_price = $discount;
                $product->save();
            }

            foreach ($row->option_changes ?? [] as $change) {
                $option = ProductOption::query()->with('group')->find((int) $change['option_id']);

                if ($option === null || (int) $option->group?->product_id !== (int) $product->id) {
                    continue;
                }

                $option->price_delta = (float) $change['requested_price_delta'];
                $option->save();
            }

            $row->forceFill([
                'status' => ProductPriceRequest::APPROVED,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();

            return $row;
        });

        self::tellPlace($approved, 'price_request.approved');

        return $approved;
    }

    public static function reject(ProductPriceRequest $request, User $admin, string $reason): ProductPriceRequest
    {
        $rejected = DB::transaction(function () use ($request, $admin, $reason): ProductPriceRequest {
            /** @var ProductPriceRequest $row */
            $row = ProductPriceRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            self::assertPending($row);

            $row->forceFill([
                'status' => ProductPriceRequest::REJECTED,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'review_note' => $reason,
            ])->save();

            return $row;
        });

        self::tellPlace($rejected, 'price_request.rejected', ['reason' => $reason]);

        return $rejected;
    }

    /** The place takes a pending request back. */
    public static function withdraw(ProductPriceRequest $request): ProductPriceRequest
    {
        return DB::transaction(function () use ($request): ProductPriceRequest {
            /** @var ProductPriceRequest $row */
            $row = ProductPriceRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            self::assertPending($row);

            $row->forceFill(['status' => ProductPriceRequest::WITHDRAWN])->save();

            return $row;
        });
    }

    /**
     * The price a request shows as its headline: what the customer would pay for the dish
     * itself once it is approved (`discount_price` when there is one, else `price`).
     *
     * @return array{current: float, requested: float}
     */
    public static function effectivePrices(ProductPriceRequest $request): array
    {
        $current = $request->current_discount_price !== null ? (float) $request->current_discount_price : (float) $request->current_price;

        $price = $request->requested_price !== null ? (float) $request->requested_price : (float) $request->current_price;
        $discount = $request->clears_discount ? null
            : ($request->requested_discount_price !== null ? (float) $request->requested_discount_price
                : ($request->current_discount_price === null ? null : (float) $request->current_discount_price));

        return ['current' => $current, 'requested' => $discount ?? $price];
    }

    /** The admins whose scope covers the place and who may approve prices. */
    public static function officersFor(?Restaurant $restaurant)
    {
        if ($restaurant === null) {
            return collect();
        }

        return User::query()
            ->where('type', UserType::Admin)
            ->where('is_active', true)
            ->get()
            ->filter(function (User $admin) use ($restaurant): bool {
                try {
                    if (! $admin->checkPermissionTo('products.approve_prices')) {
                        return false;
                    }
                } catch (Throwable) {
                    return false;
                }

                return AdminScope::applyToRestaurants(Restaurant::query()->withTrashed(), $admin)
                    ->whereKey($restaurant->id)
                    ->exists();
            })
            ->values();
    }

    private static function assertPending(ProductPriceRequest $row): void
    {
        if (! $row->isPending()) {
            throw new ApiException('price_request_closed', 'تمت معالجة هذا الطلب من قبل', 409, meta: ['status' => $row->status]);
        }
    }

    private static function same(float $a, float $b): bool
    {
        return abs(round($a, 2) - round($b, 2)) < 0.005;
    }

    /** @param  array<string, mixed>  $state */
    private static function sameState(ProductPriceRequest $previous, array $state): bool
    {
        $sameNumber = fn ($a, $b): bool => ($a === null && $b === null)
            || ($a !== null && $b !== null && self::same((float) $a, (float) $b));

        if (! $sameNumber($previous->requested_price, $state['requested_price'])
            || ! $sameNumber($previous->requested_discount_price, $state['requested_discount_price'])
            || (bool) $previous->clears_discount !== (bool) $state['clears_discount']) {
            return false;
        }

        $before = collect($previous->option_changes ?? [])->mapWithKeys(fn ($row) => [(int) $row['option_id'] => (float) $row['requested_price_delta']]);
        $after = collect($state['option_changes'])->mapWithKeys(fn ($row) => [(int) $row['option_id'] => (float) $row['requested_price_delta']]);

        return $before->count() === $after->count()
            && $after->every(fn (float $delta, int $id) => $before->has($id) && self::same($delta, (float) $before[$id]));
    }

    private static function productName(ProductPriceRequest $request): array
    {
        $product = $request->product;

        return ['ar' => (string) ($product?->name_ar ?? ''), 'en' => (string) ($product?->name_en ?: $product?->name_ar ?? '')];
    }

    private static function tellOffice(ProductPriceRequest $request): void
    {
        try {
            $restaurant = $request->restaurant;
            $dispatcher = app(NotificationDispatcher::class);

            foreach (self::officersFor($restaurant) as $admin) {
                $dispatcher->send($admin, 'price_request.new_admin', [
                    'restaurant' => ['ar' => (string) $restaurant?->name_ar, 'en' => (string) ($restaurant?->name_en ?: $restaurant?->name_ar)],
                    'product' => self::productName($request),
                ], '/price-requests/'.$request->id, data: [
                    'price_request_id' => (string) $request->id,
                    'restaurant_id' => (string) $request->restaurant_id,
                ]);
            }
        } catch (Throwable $e) {
            // An alert is never worth failing the save that raised it.
            Log::warning('price request alert failed', ['price_request_id' => $request->id, 'error' => $e->getMessage()]);
        }
    }

    /** @param  array<string, mixed>  $context */
    private static function tellPlace(ProductPriceRequest $request, string $type, array $context = []): void
    {
        try {
            app(NotificationDispatcher::class)->send($request->restaurant?->owner, $type, $context + [
                'product' => self::productName($request),
            ], '/price-requests', data: [
                'price_request_id' => (string) $request->id,
                'product_id' => (string) $request->product_id,
            ]);
        } catch (Throwable $e) {
            Log::warning('price request decision push failed', ['price_request_id' => $request->id, 'error' => $e->getMessage()]);
        }
    }
}
