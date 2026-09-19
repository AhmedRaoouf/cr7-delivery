<?php

namespace App\Support\Media;

use App\Enums\MediaUsage;
use App\Enums\MediaVisibility;
use App\Exceptions\ApiException;
use App\Models\MediaAsset;
use App\Models\MediaAudience;
use App\Models\PlaceGroup;
use App\Models\Restaurant;
use App\Models\StoreCategory;
use App\Models\User;
use App\Support\FileUploader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Write side of the media library (doc 71): upload, metadata, visibility rules.
 *
 * Uploaded files live in the stable `media-library/` folder of the uploads disk — never in
 * `products/` or `categories/` — and nothing in {@see FileUploader::deletePublic()} will
 * remove them.
 */
final class MediaStore
{
    /** @param  array<string, mixed>  $attrs  name_ar, name_en, usage_types, visibility, tags, audiences */
    public static function create(UploadedFile $file, array $attrs, ?User $actor): MediaAsset
    {
        $path = FileUploader::storePublicPath($file, MediaAsset::FOLDER);
        $disk = FileUploader::disk();
        $bytes = Storage::disk($disk)->get($path);
        $sha1 = sha1($bytes);

        $existing = MediaAsset::query()->where('sha1', $sha1)->first();

        if ($existing !== null) {
            Storage::disk($disk)->delete($path);

            throw new ApiException(
                'media_duplicate',
                'هذه الصورة موجودة بالفعل في المكتبة',
                409,
                meta: ['existing_id' => $existing->id],
            );
        }

        $absolute = method_exists(Storage::disk($disk), 'path') ? Storage::disk($disk)->path($path) : null;
        [$w, $h, $mime] = $absolute !== null ? ShippedAssets::probe($absolute) : [null, null, null];

        return DB::transaction(function () use ($attrs, $actor, $disk, $path, $bytes, $sha1, $w, $h, $mime, $file): MediaAsset {
            $asset = MediaAsset::create([
                'name_ar' => $attrs['name_ar'] ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                'name_en' => $attrs['name_en'] ?? null,
                'filename' => basename($path),
                'disk' => $disk,
                'path' => $path,
                'mime' => $mime,
                'width' => $w,
                'height' => $h,
                'bytes' => strlen($bytes),
                'sha1' => $sha1,
                'source' => MediaAsset::SOURCE_UPLOADED,
                'usage_types' => $attrs['usage_types'] ?? [MediaUsage::Product->value],
                'visibility' => $attrs['visibility'] ?? MediaVisibility::Global->value,
                'created_by' => $actor?->id,
            ]);

            self::applyRelations($asset, $attrs);

            return $asset;
        });
    }

    /**
     * Metadata, tags and the visibility rule in one write. Absent keys are left alone.
     *
     * @param  array<string, mixed>  $data
     */
    public static function update(MediaAsset $asset, array $data): MediaAsset
    {
        return DB::transaction(function () use ($asset, $data): MediaAsset {
            $usage = $data['usage_types'] ?? null;

            // The protected fallback keeps its role, whatever a form sends.
            if ($asset->is_protected && $usage !== null && ! in_array(MediaUsage::Fallback->value, $usage, true)) {
                $usage[] = MediaUsage::Fallback->value;
            }

            $asset->fill(array_filter([
                'name_ar' => $data['name_ar'] ?? null,
                'name_en' => $data['name_en'] ?? null,
                'usage_types' => $usage,
                'visibility' => $data['visibility'] ?? null,
            ], fn ($v) => $v !== null))->save();

            self::applyRelations($asset, $data);

            if ($asset->system_key === MediaLibrary::PLACEHOLDER_KEY) {
                MediaLibrary::forgetPlaceholder();
            }

            return $asset;
        });
    }

    /** @param  array<string, mixed>  $data */
    private static function applyRelations(MediaAsset $asset, array $data): void
    {
        if (array_key_exists('tags', $data)) {
            $asset->tags()->sync(ShippedAssets::tagIds((array) $data['tags']));
        }

        $visibility = $asset->visibility;

        // A rule only means something on a `restricted` asset; switching to global/hidden
        // clears it so a later switch back does not resurrect a forgotten audience.
        if ($visibility !== MediaVisibility::Restricted) {
            $asset->audiences()->delete();

            return;
        }

        if (! array_key_exists('audiences', $data)) {
            return;
        }

        $rows = collect((array) $data['audiences'])
            ->map(fn (array $a) => ['type' => (string) $a['type'], 'id' => (int) $a['id']])
            ->unique(fn (array $a) => $a['type'].':'.$a['id'])
            ->values();

        self::assertAudiencesExist($rows->all());

        $asset->audiences()->delete();

        foreach ($rows as $row) {
            MediaAudience::create([
                'media_asset_id' => $asset->id,
                'audience_type' => $row['type'],
                'audience_id' => $row['id'],
            ]);
        }
    }

    /** @param  list<array{type: string, id: int}>  $rows */
    private static function assertAudiencesExist(array $rows): void
    {
        $bad = [];

        foreach ($rows as $row) {
            $exists = match ($row['type']) {
                MediaAudience::PLACE => Restaurant::query()->whereKey($row['id'])->exists(),
                MediaAudience::GROUP => PlaceGroup::query()->whereKey($row['id'])->exists(),
                MediaAudience::STORE_CATEGORY => StoreCategory::query()->whereKey($row['id'])->exists(),
                default => false,
            };

            if (! $exists) {
                $bad[] = $row;
            }
        }

        if ($bad !== []) {
            throw new ApiException('media_audience_not_found', 'بعض الجهات المحددة غير موجودة', 422, errors: ['audiences' => $bad]);
        }
    }
}
