<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use App\Support\PriceBand;
use Illuminate\Console\Command;

/** Backfill for D-60 — run once after the column lands, and after any bulk menu import. */
class RecomputePriceBands extends Command
{
    protected $signature = 'restaurants:recompute-price-bands';

    protected $description = 'Recompute restaurants.price_band from each menu\'s median base price (D-60)';

    public function handle(): int
    {
        $counts = ['budget' => 0, 'mid' => 0, 'premium' => 0, 'null' => 0];

        Restaurant::query()->chunkById(100, function ($restaurants) use (&$counts) {
            foreach ($restaurants as $restaurant) {
                $band = PriceBand::recompute($restaurant);
                $counts[$band ?? 'null']++;
            }
        });

        foreach ($counts as $band => $count) {
            $this->line(sprintf('%-8s %d', $band, $count));
        }

        return self::SUCCESS;
    }
}
