<?php

namespace Database\Seeders;

use App\Models\Cuisine;
use Illuminate\Database\Seeder;

/**
 * The cuisine cards' artwork, and **only** that.
 *
 * Nine of the fifteen cuisines carried `image = null` on the deployment while the
 * files sat in `public/catalog/cuisines/` and answered ‪200‬ — `catalog.json` grew
 * the nine paths on 2026-09-09, after the rows had been created, and nothing has
 * re-read it since. So `CUS-12`'s first six chips were photographs and the other
 * nine were the bundled fallback marks, several of them the same mark twice: the
 * operator read that as «الصور ناقصة» on 2026-09-12, and it was one column.
 *
 * {@see CatalogSeeder} would have fixed it and would also have rewritten every
 * restaurant, menu, product, banner, popup and promo code from the seed file —
 * which on a live deployment means undoing whatever the operator edited from the
 * dashboard. Artwork is not worth that, so this is the narrow half: it writes
 * `image` and `icon` on rows matched by `name_ar` and touches nothing else. It
 * creates nothing either — a cuisine the seed file does not know is the
 * operator's own, and it keeps its picture.
 *
 * Idempotent and safe on every deploy.
 */
class CuisineArtworkSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/catalog.json');

        if (! is_file($path)) {
            $this->command?->warn('no catalog.json — cuisine artwork not refreshed');

            return;
        }

        $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $touched = 0;

        foreach ($data['cuisines'] ?? [] as $row) {
            $image = $row['image'] ?? null;

            if ($image === null) {
                continue;
            }

            $file = public_path($image);

            // The version query keeps a replaced photograph from living in a CDN or a
            // phone's image cache under its old bytes. A path with no file behind it
            // still publishes — the deployment may carry the file and the build host
            // may not — it simply has nothing to version by.
            $url = is_file($file)
                ? asset($image).'?v='.substr(md5((string) filemtime($file).':'.filesize($file)), 0, 8)
                : asset($image);

            $touched += Cuisine::query()
                ->where('name_ar', $row['name_ar'])
                ->update(['image' => $url, 'icon' => $row['icon']]);
        }

        $this->command?->info(sprintf('Refreshed artwork on %d cuisines.', $touched));
    }
}
