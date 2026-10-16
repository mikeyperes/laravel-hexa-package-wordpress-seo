<?php

namespace hexa_package_wordpress_seo\Console;

use hexa_package_wordpress_seo\Services\FeaturedImageFolders;
use Illuminate\Console\Command;
use Throwable;

class WordPressSeoFeaturedFolderCommand extends Command
{
    protected $signature = 'wordpress-seo:featured-folder
        {domain : Site domain, for example michaelperes.com}
        {--create : Create the folder when it does not exist yet}
        {--json : Print JSON}';

    protected $description = 'Find (or create) a site\'s "<domain>" folder inside the featured images Drive folder.';

    public function handle(FeaturedImageFolders $folders): int
    {
        try {
            $result = $folders->forDomain((string) $this->argument('domain'), (bool) $this->option('create'));
        } catch (Throwable $e) {
            $this->option('json')
                ? $this->line(json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES))
                : $this->error($e->getMessage());

            return self::FAILURE;
        }
        if ($this->option('json')) {
            $this->line(json_encode(['success' => true] + $result, JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        $this->line('domain: '.$result['domain']);
        $this->line('folder: '.($result['folder_url'] ?? '(not created yet; rerun with --create)'));

        return self::SUCCESS;
    }
}
