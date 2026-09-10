<?php

namespace App\Models;

use App\Enums\BannerAudience;
use App\Enums\BannerLinkType;
use App\Enums\HomePopupFrequency;
use App\Enums\HomePopupSecondaryAction;
use App\Support\BusinessTime;
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
        'secondary_action', 'frequency', 'days_of_week', 'show_countdown', 'is_active', 'sort_order',
        'starts_at', 'ends_at', 'theme',
    ];

    protected function casts(): array
    {
        return [
            'link_type' => BannerLinkType::class,
            'audience' => BannerAudience::class,
            'secondary_action' => HomePopupSecondaryAction::class,
            'frequency' => HomePopupFrequency::class,
            'days_of_week' => 'array',
            'show_countdown' => 'boolean',
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
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->where(fn ($q) => $q->whereNull('days_of_week')->orWhereJsonContains('days_of_week', self::todayIndex()));
    }

    /**
     * `0 = Sunday … 6 = Saturday` for the **local** day, not UTC's.
     *
     * A Cairo Thursday begins at 22:00Z on Wednesday. Reading `now()->dayOfWeek` on a
     * UTC server means a «خميس وجمعة وسبت» offer switches on two hours into Thursday
     * and off two hours into Sunday — the two hours either side being exactly the
     * evening rush the offer was written for.
     */
    public static function todayIndex(): int
    {
        return (int) BusinessTime::now()->dayOfWeek;
    }

    /**
     * A window's remaining seconds, or null when there is nothing to count.
     *
     * Null covers three different «no clock» cases and deliberately does not
     * distinguish them for the client: the operator did not ask for a countdown, the
     * card has no end, or the end is already behind us (`scopeLive` will have dropped
     * it, but a card fetched by id has no such filter).
     */
    public function countdownSeconds(): ?int
    {
        if (! $this->show_countdown || $this->ends_at === null) {
            return null;
        }

        $seconds = (int) now()->diffInSeconds($this->ends_at, absolute: false);

        return $seconds > 0 ? $seconds : null;
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
