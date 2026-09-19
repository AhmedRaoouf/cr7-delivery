<?php

namespace App\Http\Resources\Admin;

use App\Models\MediaAsset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The Admin's view of a library asset (doc 71). `usage_count` and the audience names are
 * handed in by the controller as two lookups for the whole page, never queried per row.
 *
 * @mixin MediaAsset
 */
class MediaAssetResource extends JsonResource
{
    /**
     * @param  array<string, int>  $counts  URL → how many fields hold it
     * @param  array<string, string>  $names  «type:id» → display name
     */
    public function __construct($resource, private readonly array $counts = [], private readonly array $names = [])
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'filename' => $this->filename,
            'url' => $this->url,
            'mime' => $this->mime,
            'width' => $this->width,
            'height' => $this->height,
            'bytes' => $this->bytes,
            'source' => $this->source,
            'source_key' => $this->source_key,
            'system_key' => $this->system_key,
            'is_protected' => (bool) $this->is_protected,
            'usage_types' => $this->usage_types,
            'visibility' => $this->visibility->value,
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->map(fn ($t) => [
                'id' => $t->id, 'slug' => $t->slug, 'name_ar' => $t->name_ar, 'name_en' => $t->name_en,
            ])->all()),
            'audiences' => $this->whenLoaded('audiences', fn () => $this->audiences->map(fn ($a) => [
                'type' => $a->audience_type,
                'id' => $a->audience_id,
                'name' => $this->names[$a->audience_type.':'.$a->audience_id] ?? null,
            ])->all()),
            'usage_count' => $this->counts[$this->url] ?? 0,
            'is_removed' => $this->deleted_at !== null,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
