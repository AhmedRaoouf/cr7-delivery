<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One rule of a `restricted` asset: a place, a place group or a store category. */
class MediaAudience extends Model
{
    public const PLACE = 'place';

    public const GROUP = 'group';

    public const STORE_CATEGORY = 'store_category';

    protected $table = 'media_asset_audiences';

    protected $fillable = ['media_asset_id', 'audience_type', 'audience_id'];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }
}
