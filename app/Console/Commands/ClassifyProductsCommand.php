<?php

namespace App\Console\Commands;

use App\Models\Cuisine;
use App\Models\Product;
use App\Support\Catalog\CuisineClassifier;
use App\Support\Http\PublicCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Doc 82 — file every dish that has no cuisine yet under one, by its name and its
 * section's name ({@see CuisineClassifier}); «أخرى» when no rule fits.
 *
 * A dish somebody already filed is never touched, so a rerun only files what is new.
 * `--dry-run` prints what would be written and writes nothing.
 */
class ClassifyProductsCommand extends Command
{
    protected $signature = 'cr7:classify-products
        {--dry-run : Print the result, write nothing}
        {--list= : Also print every dish filed under this cuisine slug (or "other")}';

    protected $description = 'Give every product without a cuisine one, guessed from its name (doc 82).';

    public function handle(): int
    {
        CuisineClassifier::flush();

        if (CuisineClassifier::fallbackId() === null) {
            $this->error('No fallback cuisine («أخرى»). Run the migrations first.');

            return self::FAILURE;
        }

        $names = Cuisine::query()->pluck('name_ar', 'id');
        $tally = [];
        $listed = [];
        $want = $this->option('list');

        $products = Product::withTrashed()
            ->whereNull('cuisine_id')
            ->with('restaurant:id,store_category_id')
            ->orderBy('id')
            ->get();

        $writes = [];

        foreach ($products as $product) {
            $id = CuisineClassifier::idFor($product);
            $writes[$id][] = $product->id;
            $name = $names[$id] ?? '?';
            $tally[$name] = ($tally[$name] ?? 0) + 1;

            if ($want !== null && Cuisine::query()->whereKey($id)->value('icon') === $want) {
                $listed[] = "#{$product->id} r{$product->restaurant_id} {$product->name_ar}";
            }
        }

        arsort($tally);
        $this->table(['cuisine', 'dishes'], collect($tally)->map(fn ($n, $k) => [$k, $n])->values()->all());
        foreach ($listed as $line) {
            $this->line($line);
        }

        if ($this->option('dry-run')) {
            $this->info(count($products).' dishes would be filed. Nothing written (--dry-run).');

            return self::SUCCESS;
        }

        // A query builder update: no model events, so the catalogue cache is bumped once.
        DB::transaction(function () use ($writes): void {
            foreach ($writes as $cuisineId => $ids) {
                foreach (array_chunk($ids, 500) as $chunk) {
                    Product::withTrashed()->whereIn('id', $chunk)->whereNull('cuisine_id')->update(['cuisine_id' => $cuisineId]);
                }
            }
        });

        PublicCache::bump('catalog');
        $this->info(count($products).' dishes filed.');

        return self::SUCCESS;
    }
}
