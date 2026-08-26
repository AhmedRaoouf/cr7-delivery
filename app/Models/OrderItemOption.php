<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItemOption extends Model
{
    protected $fillable = [
        'order_item_id', 'product_option_id', 'group_name_snapshot',
        'option_name_ar_snapshot', 'option_name_en_snapshot', 'price_delta_snapshot',
    ];

    protected function casts(): array
    {
        return ['price_delta_snapshot' => 'decimal:2'];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function productOption(): BelongsTo
    {
        return $this->belongsTo(ProductOption::class);
    }
}
