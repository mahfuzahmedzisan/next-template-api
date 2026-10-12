<?php

namespace App\Console\Commands;

use App\Services\Seo\SeoFileGenerator;
use Illuminate\Console\Command;

class SeoGenerateCommand extends Command
{
    protected $signature = 'seo:generate {--file=all : sitemap, robots, llms or all}';

    protected $description = 'Build sitemap.xml, robots.txt and llms.txt from the stored SEO settings';

    public function handle(SeoFileGenerator $generator): int
    {
        $file = (string) $this->option('file');

        if ($file !== 'all' && ! in_array($file, SeoFileGenerator::FILES, true)) {
            $this->error('Use --file=sitemap, robots, llms or all.');

            return self::INVALID;
        }

        foreach ($generator->generate($file) as $name => $meta) {
            $this->line(sprintf(
                '%s: %s',
                $generator->publicName($name),
                $meta['enabled'] ? number_format((int) $meta['size']).' bytes'.($meta['urls'] !== null ? ', '.$meta['urls'].' URLs' : '') : 'turned off',
            ));
        }

        return self::SUCCESS;
    }
}
