<?php

namespace hexa_package_wordpress_seo\Console;

use hexa_package_wordpress_seo\Services\RankMathLlmsTxtService;
use hexa_package_wordpress_seo\Services\WordPressSeoDiscoveryService;
use Illuminate\Console\Command;

/**
 * One site's llms.txt through Rank Math: status, apply (switch on and fill
 * in) or off. Prints JSON.
 */
class WordPressSeoLlmsTxtCommand extends Command
{
    protected $signature = 'wordpress-seo:llms-txt
        {domain : The site domain, e.g. example.com}
        {action=status : status|apply|off}
        {--post-types= : Comma-separated content types (default: those in the Rank Math sitemap)}
        {--taxonomies= : Comma-separated categories (default: those in the Rank Math sitemap)}
        {--limit= : Links per content type}
        {--site-name= : Rank Math site name, the llms.txt heading (also its structured data name)}
        {--summary= : One or two sentences under the heading}
        {--extra-file= : File whose text is added at the end of llms.txt}';

    protected $description = 'Read, switch on and fill in, or switch off Rank Math\'s llms.txt on one WordPress site, checked against the served file.';

    public function handle(WordPressSeoDiscoveryService $discovery, RankMathLlmsTxtService $llms): int
    {
        $target = $discovery->resolveInstallTarget((string) $this->argument('domain'));
        if (!$target) {
            $this->error('No single WordPress installation matches ' . $this->argument('domain') . '.');

            return self::FAILURE;
        }

        $options = [];
        foreach (['post-types' => 'post_types', 'taxonomies' => 'taxonomies'] as $option => $key) {
            if ($this->option($option) !== null) {
                $options[$key] = array_values(array_filter(array_map('trim', explode(',', (string) $this->option($option)))));
            }
        }
        if ($this->option('limit') !== null) {
            $options['limit'] = max(1, (int) $this->option('limit'));
        }
        foreach (['site-name' => 'site_name', 'summary' => 'summary'] as $option => $key) {
            if ($this->option($option) !== null) {
                $options[$key] = (string) $this->option($option);
            }
        }
        if ($this->option('extra-file') !== null) {
            $file = (string) $this->option('extra-file');
            if (!is_readable($file)) {
                $this->error('Cannot read ' . $file . '.');

                return self::FAILURE;
            }
            $options['extra_content'] = trim((string) file_get_contents($file));
        }

        $result = match ((string) $this->argument('action')) {
            'status' => $llms->status($target),
            'apply' => $llms->apply($target, $options),
            'off' => $llms->disable($target),
            default => ['success' => false, 'message' => 'Unknown action: ' . $this->argument('action')],
        };

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return ($result['success'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
