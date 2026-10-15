<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CartItem extends Model
{
    protected $fillable = ['cart_id', 'restaurant_id', 'product_id', 'quantity', 'note'];

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * Doc 91 — a line whose place or dish went away is read and flagged unavailable,
     * never dereferenced as null: that was a 500 on every cart call.
     */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class)->withTrashed();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function options(): HasMany
    {
        return $this->hasMany(CartItemOption::class);
    }
}
