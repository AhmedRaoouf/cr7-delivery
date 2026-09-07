<?php

namespace App\Models;

use App\Enums\MenuLayout;
use App\Support\BusinessTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Restaurant extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'city_id', 'service_zone_id', 'name_ar', 'name_en',
        'description_ar', 'description_en', 'slug', 'logo', 'cover', 'phone',
        'whatsapp', 'address', 'latitude', 'longitude', 'commission_rate',
        'min_order_value', 'avg_prep_minutes', 'is_open_manual', 'is_active',
        'is_verified', 'is_featured', 'direct_orders_enabled', 'menu_layout',
        'store_category_id', 'is_24_7',
        'sort_order', 'price_band', 'tagline_ar', 'tagline_en', 'menu_sheets', 'brand_color',
    ];

    protected function casts(): array
    {
        return [
            'menu_layout' => MenuLayout::class,
            'menu_sheets' => 'array',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'commission_rate' => 'decimal:2',
            'min_order_value' => 'decimal:2',
            'rating_avg' => 'decimal:2',
            'is_open_manual' => 'boolean',
            'is_active' => 'boolean',
            'is_verified' => 'boolean',
            'is_featured' => 'boolean',
            'direct_orders_enabled' => 'boolean',
            'is_24_7' => 'boolean',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function serviceZone(): BelongsTo
    {
        return $this->belongsTo(ServiceZone::class);
    }

    public function storeCategory(): BelongsTo
    {
        return $this->belongsTo(StoreCategory::class);
    }

    public function cuisines(): BelongsToMany
    {
        return $this->belongsToMany(Cuisine::class, 'cuisine_restaurant');
    }

    public function servedZones(): BelongsToMany
    {
        return $this->belongsToMany(ServiceZone::class, 'service_zone_restaurant');
    }

    public function openingHours(): HasMany
    {
        return $this->hasMany(RestaurantOpeningHour::class);
    }

    public function menuCategories(): HasMany
    {
        return $this->hasMany(MenuCategory::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function complaints(): HasMany
    {
        return $this->hasMany(Complaint::class);
    }

    public function ratings(): MorphMany
    {
        return $this->morphMany(Rating::class, 'rateable');
    }

    /** doc 08 §4 — is_open_now = the manual switch AND (no hours set, or today's window covers now). */
    public function isOpenNow(): bool
    {
        if (! $this->is_open_manual || ! $this->is_active) {
            return false;
        }

        // «مفتوح 24 ساعة» is the default and it is a real answer, not an empty week.
        // A shop that keeps no hours row already read as open — but one that kept a
        // stale week from before the operator flipped the switch did not, and the
        // switch is the thing they just told us to believe.
        if ($this->is_24_7) {
            return true;
        }

        // `BusinessTime`, not `now()`. Opening hours are a clock on a wall in Gamasa and
        // the server runs UTC, so this compared 10:00 Cairo against 07:00 UTC and read
        // every restaurant as closed from 04:00 to 12:00 local — the switch in the header
        // wrote `is_open_manual = true`, the API answered 200, and the label still said
        // «المطعم مغلق». The weekday has to move with the hour: at 01:00 Cairo it is still
        // the previous day in UTC, which is a different row.
        $clock = BusinessTime::now();

        $today = $this->relationLoaded('openingHours')
            ? $this->openingHours->firstWhere('weekday', $clock->dayOfWeek)
            : $this->openingHours()->where('weekday', $clock->dayOfWeek)->first();

        if (! $today) {
            return true;
        }

        if ($today->is_closed || ! $today->opens_at || ! $today->closes_at) {
            return false;
        }

        $now = $clock->format('H:i:s');

        // A shift that closes before it opens runs past midnight — «من ‪10:00‬ حتى
        // ‪02:00‬» is one window, not an empty one. Compared as two halves rather
        // than one range, or a beach-town kitchen reads as closed all evening.
        if ($today->closes_at < $today->opens_at) {
            return $now >= $today->opens_at || $now <= $today->closes_at;
        }

        return $now >= $today->opens_at && $now <= $today->closes_at;
    }
}
