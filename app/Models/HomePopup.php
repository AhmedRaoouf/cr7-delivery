<?php

namespace App\Models;

use App\Enums\BannerAudience;
use App\Enums\BannerLinkType;
use App\Enums\HomePopupFrequency;
use App\Enums\HomePopupSecondaryAction;
use App\Support\FirstOrderPerk;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The card the customer meets on the home screen — «العرض المنبثق».
 *
 * `link_type` is cast to **`BannerLinkType`**, not to a popup-shaped enum of its own.
 * The two carry the same six destinations by design («تبقى ربطه بالحاجات اللي جوا برضه
 * زي العروض»), and a second enum would be a second place to remember when a seventh kind
 * of destination is added — which is exactly how `banners` lost `product` for a year.
 */
class HomePopup extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title_ar', 'title_en', 'body_ar', 'body_en', 'image',
        'link_type', 'restaurant_id', 'product_id', 'store_category_id', 'code', 'url',
        'audience',
        'primary_label_ar', 'primary_label_en', 'secondary_label_ar', 'secondary_label_en',
        'secondary_action', 'frequency', 'is_active', 'sort_order',
        'starts_at', 'ends_at', 'theme',
    ];

    protected function casts(): array
    {
        return [
            'link_type' => BannerLinkType::class,
            'audience' => BannerAudience::class,
            'secondary_action' => HomePopupSecondaryAction::class,
            'frequency' => HomePopupFrequency::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function storeCategory(): BelongsTo
    {
        return $this->belongsTo(StoreCategory::class);
    }

    /**
     * On air right now: switched on, started, not finished.
     *
     * A null window end is «until I say otherwise», not «expired» — the operator writes
     * a card at midnight with no dates on it and it has to appear, so both bounds treat
     * null as open rather than as a failed comparison.
     */
    public function scopeLive(Builder $query): Builder
    {
        $now = now();

        return $query
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now));
    }

    /**
     * Narrows a live queue to the cards this viewer is the audience for.
     *
     * A visitor who has not signed in sees only `all`: the platform cannot know
     * whether they are new, and a card promising a new account free delivery is
     * a promise the checkout would not keep.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<HomePopup>  $query
     * @return \Illuminate\Database\Eloquent\Builder<HomePopup>
     */
    public function scopeForViewer(Builder $query, ?User $viewer): Builder
    {
        $audiences = [BannerAudience::All->value];

        if ($viewer !== null) {
            $audiences[] = FirstOrderPerk::appliesTo($viewer)
                ? BannerAudience::FirstOrder->value
                : BannerAudience::Returning->value;
        }

        return $query->whereIn('audience', $audiences);
    }
}
