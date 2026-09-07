<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\App;

class Notification extends Model
{
    protected $fillable = ['user_id', 'type', 'title_key', 'payload', 'deep_link', 'order_id', 'read_at'];

    /**
     * The table stores `title_key` + a two-locale `payload`; it has no `title` or `body`
     * column. Every client reads `title`/`body`, so without these the notification centre
     * on both dashboards rendered a dot, a dash and a timestamp — `RES-26` looked like a
     * list of empty rows because the copy was one key away and nobody was resolving it.
     *
     * Resolved per request: `SetLocaleFromHeader` has already run, so an operator who
     * switched the dashboard to English reads the English side of a row that was written
     * while they were on Arabic.
     */
    protected $appends = ['title', 'body'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'read_at' => 'datetime',
        ];
    }

    protected function title(): Attribute
    {
        return Attribute::get(fn (): string => $this->copy('title') ?? (string) $this->title_key);
    }

    protected function body(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->copy('body'));
    }

    /** Reads `<field>_<locale>` out of the payload, falling back to the other locale. */
    private function copy(string $field): ?string
    {
        $payload = $this->payload;

        if (! is_array($payload)) {
            return null;
        }

        $locale = str_starts_with(App::getLocale(), 'en') ? 'en' : 'ar';
        $other = $locale === 'en' ? 'ar' : 'en';

        foreach ([$field.'_'.$locale, $field.'_'.$other, $field] as $key) {
            $value = $payload[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class);
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }
}
