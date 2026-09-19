<?php

namespace App\Models;

use App\Enums\MediaVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * One reusable picture or icon (doc 71). Owned by the Admin, referenced by URL from the
 * fields that use it, and independent of all of them — see the migration header for why
 * nothing that can be deleted cascades into this table.
 *
 * The row is soft-deleted, never the file, when Admin «removes it from the library»: the
 * URL a product already stored has to keep answering.
 */
class MediaAsset extends Model
{
    use SoftDeletes;

    public const SOURCE_UPLOADED = 'uploaded';

    public const SOURCE_SHIPPED = 'shipped';

    public const SOURCE_SYSTEM = 'system';

    /** Folder on the uploads disk. Stable, and not one of the per-entity folders. */
    public const FOLDER = 'media-library';

    protected $fillable = [
        'name_ar', 'name_en', 'filename', 'disk', 'path', 'mime', 'width', 'height', 'bytes',
        'sha1', 'source', 'source_key', 'system_key', 'is_protected', 'usage_types',
        'visibility', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'usage_types' => 'array',
            'visibility' => MediaVisibility::class,
            'is_protected' => 'boolean',
            'width' => 'integer',
            'height' => 'integer',
            'bytes' => 'integer',
        ];
    }

    /** The browsable URL — computed at read time so a moved `APP_URL` does not strand it. */
    public function getUrlAttribute(): string
    {
        return self::urlFor($this->disk, $this->path);
    }

    public static function urlFor(string $disk, string $path): string
    {
        if ($disk === 'web') {
            return asset($path);
        }

        return Storage::disk($disk)->url($path);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(MediaTag::class, 'media_asset_tag');
    }

    public function audiences(): HasMany
    {
        return $this->hasMany(MediaAudience::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @param  Builder<MediaAsset>  $query */
    public function scopeForUsage(Builder $query, string $usage): Builder
    {
        return $query->whereJsonContains('usage_types', $usage);
    }
}
