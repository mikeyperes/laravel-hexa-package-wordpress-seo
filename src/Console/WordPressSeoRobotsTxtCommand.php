<?php

namespace hexa_package_wordpress_seo\Console;

use hexa_package_wordpress_seo\Services\RobotsTxtService;
use hexa_package_wordpress_seo\Services\WordPressSeoDiscoveryService;
use Illuminate\Console\Command;

/**
 * One site's robots.txt through Rank Math: status, plan (the file it should
 * serve and what changes) or apply. Prints JSON.
 */
class WordPressSeoRobotsTxtCommand extends Command
{
    protected $signature = 'wordpress-seo:robots-txt
        {domain : The site domain, e.g. example.com}
        {action=status : status|plan|apply}
        {--block=* : Page or path to block for every crawler}
        {--unblock=* : Page or path to stop blocking}';

    protected $description = 'Read one WordPress site\'s robots.txt, plan the standard Rank Math file (AI crawlers allowed, blocked pages, sitemap), or apply it.';

    public function handle(WordPressSeoDiscoveryService $discovery, RobotsTxtService $robots): int
    {
        $target = $discovery->resolveInstallTarget((string) $this->argument('domain'));
        if (!$target) {
            $this->error('No single WordPress installation matches ' . $this->argument('domain') . '.');

            return self::FAILURE;
        }

        $block = array_values((array) $this->option('block'));
        $unblock = array_values((array) $this->option('unblock'));
        $result = match ((string) $this->argument('action')) {
            'status' => $robots->status($target),
            'plan' => $robots->plan($target, $block, $unblock),
            'apply' => $robots->apply($target, $block, $unblock),
            default => ['success' => false, 'message' => 'Unknown action: ' . $this->argument('action')],
        };
        unset($result['served']['body'], $result['status']['served']['body'], $result['after']['served']['body']);

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return ($result['success'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
