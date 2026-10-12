<?php

namespace App\Console\Commands;

use App\Events\SeoInvalidated;
use App\Services\ApplicationSettings;
use Illuminate\Console\Command;

class BrandFaviconsCommand extends Command
{
    protected $signature = 'brand:favicons';

    protected $description = 'Rebuild favicon.ico and the PNG app icons from the uploaded favicon';

    public function handle(ApplicationSettings $settings): int
    {
        $set = $settings->syncFaviconSet();
        SeoInvalidated::dispatch();

        if ($set === null) {
            $this->warn('No raster favicon to convert (none set, SVG, or unreadable). The frontend renders the icons itself.');

            return self::SUCCESS;
        }

        foreach ($set as $name => $path) {
            $this->line($name.': '.$path);
        }

        return self::SUCCESS;
    }
}
