<?php

namespace hexa_package_wordpress_seo\Console;

use hexa_package_wordpress_seo\Services\PageLinkScanner;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only link scan of one page for the page-link-integrity skill. Prints JSON.
 */
class WordPressSeoPageLinksCommand extends Command
{
    protected $signature = 'wordpress-seo:page-links
        {url : The page address, e.g. https://example.com/about/}
        {--html= : Scan this saved (rendered) HTML file instead of fetching the page}
        {--no-status : Skip checking each internal link\'s status}';

    protected $description = 'Check every link on one web page: dead, empty, jump links to nothing, buttons and clickable text that go nowhere, plain-text addresses, plus the site\'s page list. Prints JSON.';

    public function handle(PageLinkScanner $scanner): int
    {
        $url = (string) $this->argument('url');
        $file = $this->option('html');

        try {
            if (!preg_match('#^https?://#i', $url)) {
                throw new \InvalidArgumentException('Pass a full http(s) page address.');
            }
            $html = null;
            if ($file) {
                if (!is_readable($file)) {
                    throw new \InvalidArgumentException('Cannot read the HTML file ' . $file . '.');
                }
                $html = (string) file_get_contents($file);
            }
            $result = $scanner->scan($url, $html, !$this->option('no-status'));
        } catch (Throwable $e) {
            $this->line(json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }

        $this->line(json_encode(['success' => true] + $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
