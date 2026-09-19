<?php

namespace Database\Seeders;

use App\Support\Media\MediaLibrarySync;
use Illuminate\Database\Seeder;

/** doc 71 §7 — rebuilds the system media from `database/data/media-system.json`. Idempotent. */
class MediaLibrarySeeder extends Seeder
{
    public function run(): void
    {
        MediaLibrarySync::run();
    }
}
