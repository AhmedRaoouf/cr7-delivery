<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id', 'product_id', 'product_name_ar_snapshot', 'product_name_en_snapshot',
        'product_image_snapshot', 'unit_price_snapshot', 'options_total', 'quantity',
        'line_total', 'note',
    ];

    /**
     * doc 08 §5 names an order line `name`, `image` and `unit_price`. The columns are
     * snapshots — `product_name_ar_snapshot` and friends — and two clients already read
     * those spellings (the captain shell, and the customer group resource maps them by
     * hand). Appending the contract's names is additive: nothing is removed, and every
     * endpoint that serializes an `OrderItem` now satisfies doc 08 without its own
     * resource. The restaurant board was the endpoint that did not.
     *
     * @var list<string>
     */
    protected $appends = ['name', 'image', 'unit_price'];

    protected function name(): Attribute
    {
        return Attribute::get(fn (): ?string => app()->getLocale() === 'en'
            ? ($this->product_name_en_snapshot ?? $this->product_name_ar_snapshot)
            : $this->product_name_ar_snapshot);
    }

    protected function image(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->product_image_snapshot);
    }

    protected function unitPrice(): Attribute
    {
        return Attribute::get(fn (): float => (float) $this->unit_price_snapshot);
    }

    protected function casts(): array
    {
        return [
            'unit_price_snapshot' => 'decimal:2',
            'options_total' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(OrderItemOption::class);
    }
}
