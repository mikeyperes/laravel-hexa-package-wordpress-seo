<?php

namespace hexa_package_wordpress_seo\Console;

use hexa_package_wordpress_seo\Services\Indexability\PublicUrlInspector;
use hexa_package_wordpress_seo\Services\PageStructureCheck;
use hexa_package_wordpress_seo\Services\RankMathPageService;
use hexa_package_wordpress_seo\Services\WordPressSeoDiscoveryService;
use hexa_package_wordpress_seo\Services\WordPressSeoInternalLinkService;
use hexa_package_wordpress_seo\Services\WordPressSiteIndexabilityService;
use Illuminate\Console\Command;

/**
 * Read-only SEO view of one WordPress site for skills: every page, the
 * indexability scan, or one page's internal links. Prints JSON.
 */
class WordPressSeoSiteCommand extends Command
{
    protected $signature = 'wordpress-seo:site
        {domain : The site domain, e.g. example.com}
        {view=pages : pages|indexability|links}
        {--page= : Page ID (required for links, optional filter for pages)}';

    protected $description = 'Read one WordPress site\'s SEO state: every page (Rank Math fields, featured image, score, served title, H1, heading order and slug checks), the indexability scan, or one page\'s link suggestions and dead links.';

    public function handle(
        WordPressSeoDiscoveryService $discovery,
        RankMathPageService $rankMath,
        WordPressSiteIndexabilityService $indexability,
        WordPressSeoInternalLinkService $links,
        PublicUrlInspector $inspector,
        PageStructureCheck $structure,
    ): int {
        $target = $discovery->resolveInstallTarget((string) $this->argument('domain'));
        if (!$target) {
            $this->error('No single WordPress installation matches ' . $this->argument('domain') . '.');

            return self::FAILURE;
        }

        $view = (string) $this->argument('view');
        $pageId = (int) $this->option('page');
        $inventory = $rankMath->inventoryPages($target, $pageId > 0 && $view === 'pages' ? ['page_id' => $pageId] : []);
        if (!($inventory['success'] ?? false)) {
            $this->error((string) ($inventory['message'] ?? 'Inventory failed.'));

            return self::FAILURE;
        }
        $pages = (array) ($inventory['pages'] ?? []);
        $slugs = array_values(array_filter(array_map(fn (array $page): string => (string) ($page['slug'] ?? ''), $pages)));

        $result = match ($view) {
            'pages' => [
                'site' => $target['url'] ?? null,
                'total' => $inventory['total'] ?? count($pages),
                'complete' => $inventory['complete'] ?? true,
                'served_checked' => $inventory['served_checked'] ?? 0,
                'pages' => array_map(fn (array $page): array => $this->row($page, $structure->check($page, $slugs)), $pages),
            ],
            'indexability' => $indexability->scan($target, $pages),
            'links' => $this->links($pages, $pageId, $links, $inspector),
            default => null,
        };

        if ($result === null) {
            $this->error('Unknown view: ' . $view);

            return self::FAILURE;
        }

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function row(array $page, array $structure): array
    {
        $served = (bool) ($page['served_checked'] ?? false);

        return [
            'id' => $page['id'] ?? null,
            'type' => $page['post_type'] ?? null,
            'status' => $page['status'] ?? null,
            'url' => $page['url'] ?? null,
            'title' => $page['title'] ?? null,
            'featured_image' => ($page['featured_image_id'] ?? 0) > 0,
            'featured_image_alt' => $page['featured_image_alt'] ?? '',
            'rank_math_score' => $page['seo_score'] ?? null,
            'focus_keyword' => $page['focus_keyword'] ?? '',
            'seo_title' => $page['seo_title'] ?? '',
            'seo_description' => $page['seo_description'] ?? '',
            'robots' => $page['robots'] ?? '',
            'canonical' => $page['canonical_url'] ?? '',
            'served_title' => $served ? ($page['served_title'] ?? '') : null,
            'served_description' => $served ? ($page['served_description'] ?? '') : null,
            'h1' => $structure['h1'],
            'heading_order' => $structure['heading_order'],
            'slug' => $page['slug'] ?? null,
            'slug_check' => $structure['slug'],
        ];
    }

    /** @return array<string, mixed> */
    private function links(array $pages, int $pageId, WordPressSeoInternalLinkService $links, PublicUrlInspector $inspector): array
    {
        $page = collect($pages)->firstWhere('id', $pageId);
        if (!$page) {
            return ['error' => 'Page ' . $pageId . ' was not found. Pass --page=<id>.'];
        }

        $outbound = array_values((array) ($page['outbound_internal_links'] ?? []));
        $checked = $inspector->inspectMany($outbound, (array) config('wordpress-seo.indexability', []));
        $dead = array_values(array_filter(array_map(
            fn (array $check): array => ['url' => $check['requested_url'] ?? null, 'status' => $check['status_code'] ?? null, 'final_url' => $check['final_url'] ?? null],
            array_filter($checked, fn ($check): bool => is_array($check) && ((int) ($check['status_code'] ?? 0) === 0 || (int) ($check['status_code'] ?? 0) >= 400)),
        )));

        return [
            'page' => ['id' => $page['id'], 'url' => $page['url'] ?? null, 'title' => $page['title'] ?? null],
            'links_out' => count($outbound),
            'dead_links' => $dead,
            'suggestions' => $links->analyze($page, $pages, ['site_url' => $page['url'] ?? '']),
        ];
    }
}
