<?php

namespace App\Support\Media;

use App\Enums\MediaVisibility;
use App\Models\MediaAsset;
use App\Models\MediaTag;
use Illuminate\Support\Facades\File;

/**
 * Rebuilds the system part of the library from `database/data/media-system.json`
 * (doc 71 §7) — idempotent, safe to run on every deploy and from any seeder.
 *
 * File facts (path, size, dimensions, hash) are refreshed every run; the human-editable
 * part (names, usage, tags, visibility) is written only when the row is created, so a
 * sync can never undo an Admin's edit. A missing file is reported, never silently
 * skipped: a system asset pointing at nothing is a broken fallback.
 */
final class MediaLibrarySync
{
    /** @return array{created: int, refreshed: int, missing: list<string>} */
    public static function run(): array
    {
        $path = database_path('data/media-system.json');
        $registry = json_decode((string) File::get($path), true, flags: JSON_THROW_ON_ERROR);

        foreach ($registry['tags'] ?? [] as $tag) {
            MediaTag::query()->firstOrCreate(
                ['slug' => $tag['slug']],
                ['name_ar' => $tag['name_ar'], 'name_en' => $tag['name_en'] ?? null, 'is_system' => true],
            );
        }

        $created = $refreshed = 0;
        $missing = [];

        foreach ($registry['assets'] ?? [] as $entry) {
            $absolute = $entry['disk'] === 'web' ? public_path($entry['path']) : null;

            if ($absolute === null || ! is_file($absolute)) {
                $missing[] = $entry['system_key'];

                continue;
            }

            [$w, $h, $mime] = ShippedAssets::probe($absolute);

            $asset = MediaAsset::withTrashed()->where('system_key', $entry['system_key'])->first();
            $isNew = $asset === null;
            $asset ??= new MediaAsset;

            $facts = [
                'filename' => basename($entry['path']),
                'disk' => $entry['disk'],
                'path' => $entry['path'],
                'mime' => $mime,
                'width' => $w,
                'height' => $h,
                'bytes' => (int) filesize($absolute),
                'sha1' => sha1_file($absolute) ?: null,
                'source' => MediaAsset::SOURCE_SYSTEM,
                'source_key' => 'system:'.$entry['system_key'],
                'system_key' => $entry['system_key'],
                'is_protected' => true,
            ];

            $asset->fill($isNew ? $facts + [
                'name_ar' => $entry['name_ar'],
                'name_en' => $entry['name_en'] ?? null,
                'usage_types' => $entry['usage_types'],
                'visibility' => $entry['visibility'] ?? MediaVisibility::Global->value,
            ] : $facts);
            $asset->deleted_at = null;
            $asset->save();

            if ($isNew) {
                $asset->tags()->sync(ShippedAssets::tagIds($entry['tags'] ?? []));
                $created++;
            } else {
                $refreshed++;
            }
        }

        MediaLibrary::forgetPlaceholder();

        return ['created' => $created, 'refreshed' => $refreshed, 'missing' => $missing];
    }
}
