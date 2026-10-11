<?php

namespace hexa_package_wordpress_seo\Console;

use hexa_package_wordpress_seo\Services\WordPressImageMetaService;
use hexa_package_wordpress_seo\Services\WordPressSeoDiscoveryService;
use Illuminate\Console\Command;

/**
 * Read or write one image's SEO fields for skills. With no field options it
 * only reads. Prints JSON.
 */
class WordPressImageMetaCommand extends Command
{
    protected $signature = 'wordpress-seo:image
        {domain : The site domain, e.g. example.com}
        {image : Attachment ID or image URL}
        {--alt= : New alt text}
        {--title= : New title}
        {--caption= : New caption}
        {--description= : New description}
        {--post= : Post ID whose content should get the new alt on this image}';

    protected $description = 'Read or update one WordPress image\'s alt text, title, caption and description (and its alt inside one post\'s content).';

    public function handle(WordPressSeoDiscoveryService $discovery, WordPressImageMetaService $images): int
    {
        $target = $discovery->resolveInstallTarget((string) $this->argument('domain'));
        if (!$target) {
            $this->error('No single WordPress installation matches ' . $this->argument('domain') . '.');

            return self::FAILURE;
        }

        $fields = [];
        foreach (WordPressImageMetaService::FIELDS as $field) {
            $value = $this->option($field);
            if ($value !== null) {
                $fields[$field] = (string) $value;
            }
        }

        $image = (string) $this->argument('image');
        $image = ctype_digit($image) ? (int) $image : $image;
        $result = $fields === []
            ? $images->read($target, $image)
            : $images->write($target, $image, $fields, (int) $this->option('post'));

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return ($result['success'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
