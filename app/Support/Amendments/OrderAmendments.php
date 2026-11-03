<?php

namespace App\Support\Amendments;

use App\Actions\Restaurant\AcceptOrderAction;
use App\Enums\OrderAmendmentStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderStatusActorType;
use App\Enums\PaymentMethod;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\OrderAmendment;
use App\Models\OrderGroup;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\PromoCodeUsage;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\CashLimit;
use App\Support\Catalog\LinePrice;
use App\Support\CheckoutPricer;
use App\Support\Notifications\OrderNotifications;
use App\Support\Notifications\RingService;
use App\Support\PaymentMethods;
use App\Support\SettingsCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * doc 99 §4, D-172 — «يتمكن المكان من مراجعة الطلب وتعديله في حالة وجود أصناف غير متوفرة مع إرسال
 * التعديلات للعميل لمراجعتها ولا يتم استكمال الطلب إلا بعد موافقة العميل».
 *
 * The whole life of an amendment, in one class so every rule is read in one place:
 *
 * - **propose** — a place whose switch is on (or an admin holding `orders.amend`), on a
 *   sub-order still `pending`, whose customer's newest device runs 1.0.24 or later. Lines
 *   may only shrink or go; additions come from the same place's menu, orderable, needing
 *   no configuration. The result must still cost something. The place's ring stops (it
 *   answered) and its answer window and the restaurant timeout pause.
 * - **accept** — by the customer, at the total they were shown (`expected_total`), never a
 *   stale one. Items rewritten, the basket re-priced by `CheckoutPricer`'s own rules, the
 *   cash limit applied, then the sub-order is accepted exactly as the place's own button
 *   accepts it — and the approval counts as the customer's confirmation.
 * - **reject / expire / withdraw** — the order stays `pending`, the place's window
 *   restarts, and it can accept the original or reject the order. Nothing is cancelled.
 *
 * Payment opens only after every place has answered (D-36), and an amendment exists only
 * while its place has not — so no money has moved and there is nothing to refund.
 */
class OrderAmendments
{
    public function __construct(
        private readonly CheckoutPricer $pricer,
        private readonly AcceptOrderAction $accept,
        private readonly OrderNotifications $notifications,
    ) {}

    // ------------------------------------------------------------------ propose

    /**
     * @param  array{prep_minutes?: int|null, restaurant_order_ref?: string|null, lines?: list<array{order_item_id: int, quantity: int}>, additions?: list<array{product_id: int, quantity: int}>, note?: string|null}  $data
     */
    public function propose(User $actor, Order $order, array $data, string $proposerType): OrderAmendment
    {
        self::assertAllowed($order, $proposerType);

        if ($order->isDirect() || $order->status !== OrderStatus::Pending) {
            throw new ApiException('order_not_pending', 'لا يمكن اقتراح تعديل إلا على طلب جديد لم يُرد عليه بعد', 409);
        }

        $customer = $order->user;

        if ($customer === null || ! CustomerAppVersion::understandsAmendments($customer)) {
            throw new ApiException(
                'customer_app_outdated',
                'نسخة العميل لا تدعم الموافقة على التعديل؛ تواصل معه أو ارفض الطلب',
                409,
                meta: ['customer_app_version' => $customer ? CustomerAppVersion::newest($customer) : null, 'minimum' => CustomerAppVersion::MINIMUM],
            );
        }

        $prepMinutes = (int) ($data['prep_minutes'] ?? 0);

        if ($prepMinutes <= 0 && $proposerType === OrderAmendment::PROPOSER_ADMIN) {
            $prepMinutes = (int) ($order->restaurant?->avg_prep_minutes ?: 20);
        }

        AcceptOrderAction::assertPrepMinutes($prepMinutes);

        $amendment = DB::transaction(function () use ($actor, $order, $data, $proposerType, $prepMinutes) {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($locked->status !== OrderStatus::Pending) {
                throw new ApiException('order_not_pending', 'لا يمكن اقتراح تعديل إلا على طلب جديد لم يُرد عليه بعد', 409);
            }

            if ($locked->hasPendingAmendment()) {
                throw new ApiException('amendment_pending', 'هناك تعديل مقترح بانتظار رد العميل بالفعل', 409);
            }

            $draft = $this->draft($locked, $data['lines'] ?? [], $data['additions'] ?? []);
            $group = $locked->orderGroup;
            $priced = $this->pricer->repriceGroup($group, [$locked->id => $draft['subtotal']]);

            $amendment = OrderAmendment::query()->create([
                'order_id' => $locked->id,
                'order_group_id' => $locked->order_group_id,
                'proposed_by' => $actor->id,
                'proposer_type' => $proposerType,
                'status' => OrderAmendmentStatus::Pending,
                'lines' => $draft['lines'],
                'additions' => $draft['additions'],
                'money_before' => self::moneyBefore($group, $locked),
                'money_after' => self::moneyAfter($priced, $locked->id),
                'delta' => round($priced['group']['total'] - (float) $group->total, 2),
                'prep_minutes' => $prepMinutes,
                'restaurant_order_ref' => ($data['restaurant_order_ref'] ?? null) ?: null,
                'note' => ($data['note'] ?? null) ?: null,
                'expires_at' => now()->addMinutes(max(1, SettingsCatalog::int('orders.amendment_response_minutes'))),
            ]);

            $locked->forceFill(['amendment_status' => OrderAmendmentStatus::Pending->value])->saveQuietly();

            $locked->statusHistory()->create([
                'from_status' => OrderStatus::Pending->value,
                'to_status' => OrderStatus::Pending->value,
                'actor_type' => $proposerType === OrderAmendment::PROPOSER_ADMIN ? OrderStatusActorType::Admin : OrderStatusActorType::Restaurant,
                'actor_id' => $actor->id,
                'reason_code' => 'amendment_proposed',
                'meta' => ['amendment_id' => $amendment->id, 'delta' => (float) $amendment->delta],
            ]);

            // The place answered: the ring that calls it to answer stops on every phone.
            app(RingService::class)->stop($locked);

            if ($proposerType === OrderAmendment::PROPOSER_ADMIN) {
                AuditLogger::record($actor, 'orders.amend', $locked, ['amendment_id' => $amendment->id, 'delta' => (float) $amendment->delta]);
            }

            $this->notifications->amendmentProposed($locked->fresh(['user', 'restaurant', 'orderGroup']), $amendment);

            return $amendment;
        });

        BoardSignal::changed($order->fresh());

        return $amendment->fresh();
    }

    /**
     * The checks that run before anything is locked: the platform switch, then the place's
     * own (an admin does not need the place's — the office is the one switching it).
     */
    public static function assertAllowed(Order $order, string $proposerType): void
    {
        if (! SettingsCatalog::bool('orders.amendments_enabled')) {
            throw self::notAllowed();
        }

        if ($proposerType === OrderAmendment::PROPOSER_RESTAURANT && ! (bool) $order->restaurant?->amendments_enabled) {
            throw self::notAllowed();
        }
    }

    /** Whether the place's «اقتراح تعديل» button should be drawn on this order now. */
    public static function placeMayPropose(Order $order): bool
    {
        return SettingsCatalog::bool('orders.amendments_enabled')
            && (bool) $order->restaurant?->amendments_enabled
            && ! $order->isDirect()
            && $order->status === OrderStatus::Pending
            && ! $order->hasPendingAmendment();
    }

    private static function notAllowed(): ApiException
    {
        return new ApiException('amendments_not_allowed', 'تعديل الطلبات غير مفعّل لهذا المكان', 403);
    }

    /**
     * The proposed order, line by line, priced and checked.
     *
     * @param  list<array{order_item_id: int|string, quantity: int|string}>  $lineChanges
     * @param  list<array{product_id: int|string, quantity: int|string}>  $additionRows
     * @return array{lines: list<array<string, mixed>>, additions: list<array<string, mixed>>, subtotal: float}
     */
    private function draft(Order $order, array $lineChanges, array $additionRows): array
    {
        $items = $order->items()->with('options')->orderBy('id')->get()->keyBy('id');
        $wanted = [];

        foreach ($lineChanges as $change) {
            $itemId = (int) $change['order_item_id'];
            $quantity = (int) $change['quantity'];

            if (! $items->has($itemId)) {
                throw new ApiException('validation_failed', 'أحد الأصناف المذكورة ليس في هذا الطلب', 422, ['lines' => ["order_item_id {$itemId}"]]);
            }

            if (array_key_exists($itemId, $wanted)) {
                throw new ApiException('validation_failed', 'ذُكر الصنف نفسه أكثر من مرة', 422, ['lines' => ["order_item_id {$itemId}"]]);
            }

            if ($quantity > (int) $items[$itemId]->quantity) {
                throw new ApiException('amendment_quantity_increase', 'يمكن تقليل الكمية أو حذف الصنف فقط، لا زيادتها', 422, meta: ['order_item_id' => $itemId]);
            }

            $wanted[$itemId] = $quantity;
        }

        $lines = [];
        $subtotal = 0.0;
        $changed = false;

        foreach ($items as $item) {
            $unit = round((float) $item->unit_price_snapshot + (float) $item->options_total, 2);
            $before = (int) $item->quantity;
            $after = $wanted[$item->id] ?? $before;
            $changed = $changed || $after !== $before;
            $subtotal += $unit * $after;

            $lines[] = [
                'order_item_id' => $item->id,
                'product_id' => $item->product_id,
                'name_ar' => $item->product_name_ar_snapshot,
                'name_en' => $item->product_name_en_snapshot,
                'image' => $item->product_image_snapshot,
                'options' => $item->options->map(fn ($o) => [
                    'name_ar' => $o->option_name_ar_snapshot,
                    'name_en' => $o->option_name_en_snapshot,
                ])->values()->all(),
                'unit_price' => $unit,
                'quantity_before' => $before,
                'quantity_after' => $after,
            ];
        }

        $plainProducts = $items->filter(fn (OrderItem $item) => $item->options->isEmpty())->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->all();
        $additions = [];
        $seen = [];

        foreach ($additionRows as $row) {
            $productId = (int) $row['product_id'];
            $quantity = (int) $row['quantity'];

            if (isset($seen[$productId])) {
                throw new ApiException('validation_failed', 'ذُكر الصنف المضاف نفسه أكثر من مرة', 422, ['additions' => ["product_id {$productId}"]]);
            }
            $seen[$productId] = true;

            /** @var Product|null $product */
            $product = Product::query()->with('optionGroups.options')->find($productId);

            if ($product === null || (int) $product->restaurant_id !== (int) $order->restaurant_id) {
                throw new ApiException('amendment_product_foreign', 'يمكن إضافة أصناف من قائمة هذا المكان فقط', 422, meta: ['product_id' => $productId]);
            }

            if (! $product->isOrderable()) {
                throw new ApiException('product_unavailable', "لم يعد متاحًا: {$product->name_ar}", 422, meta: ['product_id' => $productId]);
            }

            if ($product->requiresConfiguration()) {
                throw new ApiException('product_needs_options', "يحتاج {$product->name_ar} إلى اختيار من العميل ولا يمكن إضافته بديلًا", 422, meta: ['product_id' => $productId]);
            }

            // A dish already on the order as a plain line is that line's quantity going
            // up — which is the one change the place may not make for the customer.
            if (in_array($productId, $plainProducts, true)) {
                throw new ApiException('amendment_quantity_increase', 'يمكن تقليل الكمية أو حذف الصنف فقط، لا زيادتها', 422, meta: ['product_id' => $productId]);
            }

            $unit = self::unitPrice($product);
            $subtotal += $unit * $quantity;

            $additions[] = [
                'product_id' => $product->id,
                'name_ar' => $product->name_ar,
                'name_en' => $product->name_en,
                'image' => $product->image,
                'unit_price' => $unit,
                'quantity' => $quantity,
            ];
        }

        if (! $changed && $additions === []) {
            throw new ApiException('amendment_empty', 'لم يتغير شيء في الطلب', 422);
        }

        $subtotal = round($subtotal, 2);

        if ($subtotal <= 0) {
            throw new ApiException('amendment_subtotal_zero', 'لا يمكن أن يصبح الطلب فارغًا — ارفض الطلب بدلًا من ذلك', 422);
        }

        return ['lines' => $lines, 'additions' => $additions, 'subtotal' => $subtotal];
    }

    /** One unit of a dish added with no options — its own (discounted) price. */
    private static function unitPrice(Product $product): float
    {
        ['unit' => $unit, 'options_total' => $extras] = LinePrice::of($product, []);

        return round($unit + $extras, 2);
    }

    // ------------------------------------------------------------------ accept

    public function accept(User $customer, OrderGroup $group, OrderAmendment $amendment, float $expectedTotal): OrderAmendment
    {
        abort_unless((int) $group->user_id === (int) $customer->id && (int) $amendment->order_group_id === (int) $group->id, 404);

        DB::transaction(function () use ($customer, $group, $amendment, $expectedTotal) {
            /** @var OrderAmendment $locked */
            $locked = OrderAmendment::query()->lockForUpdate()->findOrFail($amendment->id);
            /** @var Order $order */
            $order = Order::query()->lockForUpdate()->findOrFail($locked->order_id);

            self::assertOpen($locked, $order);

            $lines = collect($locked->lines);
            $additions = collect($locked->additions)->map(function (array $row) {
                $product = Product::query()->with('optionGroups.options')->find($row['product_id']);

                // Today's menu, not the one the place proposed from: a dish switched off or
                // repriced since then is not what the customer is approving.
                if ($product === null || ! $product->isOrderable() || $product->requiresConfiguration()
                    || abs(self::unitPrice($product) - (float) $row['unit_price']) > 0.005) {
                    throw self::changed('تغيّر أحد الأصناف المقترحة منذ إرسال التعديل — اطلب من المكان إرسال تعديل جديد');
                }

                return ['product' => $product] + $row;
            });

            $subtotal = round(
                $lines->sum(fn (array $line) => (float) $line['unit_price'] * (int) $line['quantity_after'])
                + $additions->sum(fn (array $row) => (float) $row['unit_price'] * (int) $row['quantity']),
                2,
            );

            $freshGroup = OrderGroup::query()->lockForUpdate()->findOrFail($group->id);
            $priced = $this->pricer->repriceGroup($freshGroup, [$order->id => $subtotal]);
            $total = $priced['group']['total'];

            if (abs($total - $expectedTotal) > 0.005) {
                throw self::changed('تغيّر إجمالي الطلب منذ عرض التعديل — راجع التعديل مرة أخرى', ['total' => $total]);
            }

            if (! self::cashAllows($freshGroup, $customer, $total)) {
                $open = CashLimit::openCashTotal($customer, $freshGroup->id);
                $blocker = CashLimit::blocker($total, $customer, $open);

                throw new ApiException(CashLimit::CODE, $blocker['message'], 422, meta: CashLimit::meta($total, $open));
            }

            $this->rewriteItems($order, $lines, $additions);
            $this->applyMoney($freshGroup, $priced);

            $locked->update([
                'status' => OrderAmendmentStatus::Accepted,
                'responded_at' => now(),
                'responded_by' => $customer->id,
                'money_after' => self::moneyAfter($priced, $order->id),
                'delta' => round($total - (float) $locked->money_before['total'], 2),
            ]);

            $order->forceFill(['amendment_status' => OrderAmendmentStatus::Accepted->value])->saveQuietly();

            $order->statusHistory()->create([
                'from_status' => OrderStatus::Pending->value,
                'to_status' => OrderStatus::Pending->value,
                'actor_type' => OrderStatusActorType::Customer,
                'actor_id' => $customer->id,
                'reason_code' => 'amendment_accepted',
                'meta' => ['amendment_id' => $locked->id, 'total' => $total],
            ]);

            $isAdmin = $locked->proposer_type === OrderAmendment::PROPOSER_ADMIN;
            $acceptor = $locked->proposer ?? $order->restaurant?->owner ?? $customer;

            $this->accept->commit(
                $acceptor,
                $order->fresh(),
                (int) $locked->prep_minutes,
                $locked->restaurant_order_ref,
                $isAdmin ? OrderStatusActorType::Admin : OrderStatusActorType::Restaurant,
                ['meta' => ['amendment_id' => $locked->id]],
                customerApproved: true,
            );

            $this->notifications->amendmentAnswered($order->fresh(['restaurant.owner']), $locked->fresh());
        });

        return $amendment->fresh();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $lines
     * @param  Collection<int, array<string, mixed>>  $additions  each with its live `product`
     */
    private function rewriteItems(Order $order, Collection $lines, Collection $additions): void
    {
        $items = $order->items()->get()->keyBy('id');

        foreach ($lines as $line) {
            $item = $items->get((int) $line['order_item_id']);

            if ($item === null) {
                throw self::changed('تغيّر الطلب منذ إرسال التعديل — اطلب من المكان إرسال تعديل جديد');
            }

            $after = (int) $line['quantity_after'];

            if ($after === 0) {
                $item->delete();

                continue;
            }

            if ($after !== (int) $item->quantity) {
                $item->update([
                    'quantity' => $after,
                    'line_total' => round(((float) $item->unit_price_snapshot + (float) $item->options_total) * $after, 2),
                ]);
            }
        }

        foreach ($additions as $row) {
            /** @var Product $product */
            $product = $row['product'];
            $unit = (float) $row['unit_price'];
            $quantity = (int) $row['quantity'];

            // The same snapshot `CreateOrderAction` writes for a dish with no options.
            $order->items()->create([
                'product_id' => $product->id,
                'product_name_ar_snapshot' => $product->name_ar,
                'product_name_en_snapshot' => $product->name_en,
                'product_image_snapshot' => $product->image,
                'unit_price_snapshot' => $unit,
                'options_total' => 0,
                'quantity' => $quantity,
                'line_total' => round($unit * $quantity, 2),
            ]);
        }
    }

    /** @param  array{group: array<string, float>, orders: array<int, array<string, float>>}  $priced */
    private function applyMoney(OrderGroup $group, array $priced): void
    {
        $group->update([
            'subtotal' => $priced['group']['subtotal'],
            'discount_amount' => $priced['group']['discount_amount'],
            'total' => $priced['group']['total'],
        ]);

        foreach ($priced['orders'] as $orderId => $money) {
            Order::query()->whereKey($orderId)->first()?->forceFill($money)->saveQuietly();

            // The code's usage row carries the share it took from this sub-order.
            if ($group->promo_code_id !== null) {
                PromoCodeUsage::query()
                    ->where('promo_code_id', $group->promo_code_id)
                    ->where('order_id', $orderId)
                    ->update(['discount_amount' => $money['discount_share']]);
            }
        }
    }

    // ------------------------------------------------------------------ reject / withdraw / expire

    public function reject(User $customer, OrderGroup $group, OrderAmendment $amendment, ?string $reason): OrderAmendment
    {
        abort_unless((int) $group->user_id === (int) $customer->id && (int) $amendment->order_group_id === (int) $group->id, 404);

        DB::transaction(function () use ($customer, $amendment, $reason) {
            $locked = OrderAmendment::query()->lockForUpdate()->findOrFail($amendment->id);
            $order = Order::query()->lockForUpdate()->findOrFail($locked->order_id);

            self::assertOpen($locked, $order);

            $this->close($locked, $order, OrderAmendmentStatus::Rejected, OrderStatusActorType::Customer, $customer->id, $reason ?: null, 'amendment_rejected');
            $this->notifications->amendmentAnswered($order->fresh(['restaurant.owner']), $locked->fresh());
        });

        BoardSignal::changed($amendment->order()->first());

        return $amendment->fresh();
    }

    public function withdraw(User $actor, Order $order, OrderAmendment $amendment, string $proposerType): OrderAmendment
    {
        abort_unless((int) $amendment->order_id === (int) $order->id, 404);

        DB::transaction(function () use ($actor, $amendment, $proposerType) {
            $locked = OrderAmendment::query()->lockForUpdate()->findOrFail($amendment->id);
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($locked->order_id);

            if (! $locked->isPending()) {
                throw self::closed();
            }

            $actorType = $proposerType === OrderAmendment::PROPOSER_ADMIN ? OrderStatusActorType::Admin : OrderStatusActorType::Restaurant;
            $this->close($locked, $lockedOrder, OrderAmendmentStatus::Withdrawn, $actorType, $actor->id, null, 'amendment_withdrawn');

            if ($proposerType === OrderAmendment::PROPOSER_ADMIN) {
                AuditLogger::record($actor, 'orders.amend_withdraw', $lockedOrder, ['amendment_id' => $locked->id]);
            }
        });

        BoardSignal::changed($order->fresh());

        return $amendment->fresh();
    }

    /** `cr7:tick` — a change the customer did not answer in time goes back to the place. */
    public function expireDue(): int
    {
        $ids = OrderAmendment::query()
            ->where('status', OrderAmendmentStatus::Pending)
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->limit(50)
            ->pluck('id');

        $expired = 0;

        foreach ($ids as $id) {
            $done = DB::transaction(function () use ($id) {
                $locked = OrderAmendment::query()->lockForUpdate()->find($id);

                // A second tick, or the customer answering in the same second, got there first.
                if ($locked === null || ! $locked->isPending() || $locked->expires_at->isFuture()) {
                    return null;
                }

                $order = Order::query()->lockForUpdate()->find($locked->order_id);

                if ($order === null) {
                    return null;
                }

                $this->close($locked, $order, OrderAmendmentStatus::Expired, OrderStatusActorType::System, null, null, 'amendment_expired');
                $this->notifications->amendmentAnswered($order->fresh(['restaurant.owner']), $locked->fresh());

                return $order;
            });

            if ($done !== null) {
                $expired++;
                BoardSignal::changed($done->fresh());
            }
        }

        return $expired;
    }

    /**
     * `OrderObserver` — an order leaving `pending` some other way (the customer cancelled,
     * the office cancelled or forced it) takes its pending change with it: there is no
     * longer an answer to give, and «أوافق» must not accept a closed order.
     */
    public static function closeOnLeavingPending(Order $order): void
    {
        if ($order->amendment_status !== OrderAmendmentStatus::Pending->value || $order->status === OrderStatus::Pending) {
            return;
        }

        OrderAmendment::query()
            ->where('order_id', $order->id)
            ->where('status', OrderAmendmentStatus::Pending)
            ->update(['status' => OrderAmendmentStatus::Withdrawn, 'response_note' => 'order_closed', 'responded_at' => now(), 'updated_at' => now()]);

        $order->forceFill(['amendment_status' => OrderAmendmentStatus::Withdrawn->value])->saveQuietly();
    }

    private function close(
        OrderAmendment $amendment,
        Order $order,
        OrderAmendmentStatus $status,
        OrderStatusActorType $actorType,
        ?int $actorId,
        ?string $note,
        string $reason,
    ): void {
        $amendment->update([
            'status' => $status,
            'responded_at' => now(),
            'responded_by' => $actorType === OrderStatusActorType::Customer ? $actorId : null,
            'response_note' => $note,
        ]);

        // The place answers again, so its window starts again — from now, not from when
        // the order arrived, or a change the customer took nine minutes over would hand
        // the place an order that is already timed out.
        $order->forceFill([
            'amendment_status' => $status->value,
            'answer_window_started_at' => now(),
        ])->saveQuietly();

        $order->statusHistory()->create([
            'from_status' => $order->status->value,
            'to_status' => $order->status->value,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'reason_code' => $reason,
            'note' => $note,
            'meta' => ['amendment_id' => $amendment->id],
        ]);
    }

    private static function assertOpen(OrderAmendment $amendment, Order $order): void
    {
        if (! $amendment->isPending() || $order->status !== OrderStatus::Pending) {
            throw self::closed();
        }

        if ($amendment->expires_at !== null && $amendment->expires_at->lte(now())) {
            throw new ApiException('amendment_closed', 'انتهت مهلة الرد على التعديل', 409);
        }
    }

    private static function closed(): ApiException
    {
        return new ApiException('amendment_closed', 'لم يعد هذا التعديل بانتظار الرد', 409);
    }

    /** @param  array<string, mixed>  $meta */
    private static function changed(string $message, array $meta = []): ApiException
    {
        return new ApiException('amendment_changed', $message, 409, meta: $meta);
    }

    // ------------------------------------------------------------------ money

    /** Whether a cash basket may carry this total — the doc 98 rule, open cash included. */
    public static function cashAllows(OrderGroup $group, ?User $customer, float $total): bool
    {
        $method = $group->payment_method instanceof PaymentMethod ? $group->payment_method->value : (string) $group->payment_method;

        if ($method !== PaymentMethod::Cash->value || ! PaymentMethods::isEnabled(PaymentMethod::Cash)) {
            return true;
        }

        return CashLimit::allows($total, CashLimit::openCashTotal($customer, $group->id));
    }

    /** @return array{subtotal: float, discount: float, delivery_fee: float, platform_fee: float, outstanding_fees: float, total: float, order_subtotal: float} */
    private static function moneyBefore(OrderGroup $group, Order $order): array
    {
        return [
            'subtotal' => round((float) $group->subtotal, 2),
            'discount' => round((float) $group->discount_amount, 2),
            'delivery_fee' => round((float) $group->delivery_fee, 2),
            'platform_fee' => round((float) $group->platform_fee, 2),
            'outstanding_fees' => round((float) $group->outstanding_fees, 2),
            'total' => round((float) $group->total, 2),
            'order_subtotal' => round((float) $order->subtotal, 2),
        ];
    }

    /**
     * @param  array{group: array<string, float>, orders: array<int, array<string, float>>}  $priced
     * @return array{subtotal: float, discount: float, delivery_fee: float, platform_fee: float, outstanding_fees: float, total: float, order_subtotal: float}
     */
    private static function moneyAfter(array $priced, int $orderId): array
    {
        return [
            'subtotal' => $priced['group']['subtotal'],
            'discount' => $priced['group']['discount_amount'],
            'delivery_fee' => $priced['group']['delivery_fee'],
            'platform_fee' => $priced['group']['platform_fee'],
            'outstanding_fees' => $priced['group']['outstanding_fees'],
            'total' => $priced['group']['total'],
            'order_subtotal' => $priced['orders'][$orderId]['subtotal'],
        ];
    }
}
