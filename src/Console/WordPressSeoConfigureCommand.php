<?php

namespace hexa_package_wordpress_seo\Console;

use hexa_package_wordpress_seo\Settings\WordPressSeoSettings;
use Illuminate\Console\Command;

class WordPressSeoConfigureCommand extends Command
{
    protected $signature = 'wordpress-seo:configure
        {--featured-images-folder= : Google Drive parent folder URL for featured images ("" clears it)}
        {--json : Output JSON}';

    protected $description = 'Read or set the WordPress SEO settings, such as the Google Drive parent folder that holds each site\'s featured images.';

    public function handle(WordPressSeoSettings $settings): int
    {
        $folder = $this->option('featured-images-folder');
        if ($folder !== null) {
            $folder = trim((string) $folder);
            if ($folder !== '' && ! preg_match('~\Ahttps://drive\.google\.com/~', $folder)) {
                $this->error('The featured images folder must be a https://drive.google.com/ folder URL.');

                return self::FAILURE;
            }
            $settings->setFeaturedImagesParentUrl($folder);
        }

        $values = ['featured_images_parent_url' => $settings->featuredImagesParentUrl()];
        if ($this->option('json')) {
            $this->line(json_encode($values, JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        foreach ($values as $key => $value) {
            $this->line($key.': '.($value !== '' ? $value : '(not set)'));
        }

        return self::SUCCESS;
    }
}
