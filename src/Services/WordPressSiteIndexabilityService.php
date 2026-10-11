<?php

namespace hexa_package_wordpress_seo\Services;

use hexa_package_wordpress\Services\WordPressManagerService;
use hexa_package_wordpress_seo\Services\Indexability\PublicUrlInspector;
use hexa_package_wordpress_seo\Services\Indexability\RobotsTxtPolicy;
use hexa_package_wordpress_seo\Services\Indexability\SitemapManifestBuilder;

final class WordPressSiteIndexabilityService
{
    public function __construct(
        private readonly WordPressManagerService $wordpress,
        private readonly PublicUrlInspector $inspector,
        private readonly RobotsTxtPolicy $robotsPolicy,
        private readonly SitemapManifestBuilder $sitemaps,
    ) {
    }

    public function scan(array $target, array $inventoryPages = [], array $options = []): array
    {
        $settings = array_replace_recursive((array) config('wordpress-seo.indexability', []), $options);
        $state = $this->collectWordPressState($target);
        $siteUrl = rtrim((string) ($state['home_url'] ?? $target['url'] ?? ''), '/');
        if (!$this->isHttpUrl($siteUrl)) {
            return [
                "success" => false,
                "complete" => false,
                "technical_indexable" => false,
                "state" => 'incomplete',
                "message" => 'The WordPress site URL is unavailable.',
                "wordpress" => $state,
                "url_audits" => [],
            ];
        }

        $httpOptions = array_merge($settings, (array) ($settings['http'] ?? []));
        $homepage = $this->inspector->inspect($siteUrl . '/', $httpOptions);
        $preferredUrl = (string) (($homepage['final_url'] ?? '') ?: $siteUrl . '/');
        $preferredHost = strtolower((string) parse_url($preferredUrl, PHP_URL_HOST));
        $robotsUrl = $this->origin($preferredUrl) . '/robots.txt';
        $robotsResponse = $this->inspector->inspect($robotsUrl, array_merge($httpOptions, [
            "include_body" => true,
            "max_body_bytes" => max(1024, (int) ($settings['max_robots_body_bytes'] ?? 1048576)),
            "headers" => ["Accept" => 'text/plain,*/*;q=0.5'],
        ]));
        $robotsBody = (int) ($robotsResponse['status_code'] ?? 0) === 200
            ? (string) ($robotsResponse['_body'] ?? '')
            : '';
        unset($robotsResponse['_body']);
        $robots = $this->robotsPolicy->parse($robotsBody);

        $seedUrls = array_values(array_filter(array_map('strval', (array) ($settings['sitemap_urls'] ?? []))));
        if ($seedUrls === []) {
            $seedUrls = array_merge(
                (array) ($robots['sitemaps'] ?? []),
                array_values(array_filter(array_map('strval', (array) ($state['expected_sitemap_urls'] ?? []))))
            );
        }
        $seedUrls = array_values(array_unique($seedUrls));

        $manifest = $this->sitemaps->build($seedUrls, array_merge($httpOptions, $settings, [
            "expected_host" => $preferredHost,
        ]));
        $importantUrls = $this->importantUrls($state, $inventoryPages, (array) ($settings['important_urls'] ?? []));
        $auditUrls = array_values(array_unique(array_merge(
            (array) ($manifest['urls'] ?? []),
            array_column($importantUrls, 'url')
        )));
        $responses = $this->inspector->inspectMany($auditUrls, array_merge($httpOptions, [
            "concurrency" => (int) ($settings['concurrency'] ?? 2),
            "delay_ms" => (int) ($settings['delay_ms'] ?? 1000),
        ]));

        foreach ($auditUrls as $url) {
            if ($this->normalizeUrl($url) === $this->normalizeUrl($siteUrl . '/') && ($homepage['success'] ?? false)) {
                $responses[$url] = $homepage;
            }
        }

        $probeUrl = $this->origin($preferredUrl) . '/.well-known/hexa-indexability-probe-' . substr(hash('sha256', $siteUrl . microtime(true)), 0, 20) . '.html';
        $notFoundProbe = $this->inspector->inspect($probeUrl, $httpOptions);
        $manifestSet = array_fill_keys(array_map(fn (string $url): string => $this->normalizeUrl($url), (array) ($manifest['urls'] ?? [])), true);
        $importantSet = array_fill_keys(array_map(fn (array $item): string => $this->normalizeUrl((string) $item['url']), $importantUrls), true);
        $audits = [];

        foreach ($auditUrls as $url) {
            $normalized = $this->normalizeUrl($url);
            $audits[] = $this->assessUrl(
                $url,
                (array) ($responses[$url] ?? []),
                $robots,
                $preferredHost,
                $notFoundProbe,
                isset($manifestSet[$normalized]),
                isset($importantSet[$normalized]),
            );
        }

        $inboundCounts = $this->inboundCounts($audits, $preferredHost);
        $orphans = [];
        foreach ($importantUrls as $important) {
            $normalized = $this->normalizeUrl((string) $important['url']);
            $inSitemap = isset($manifestSet[$normalized]);
            $inboundCount = (int) ($inboundCounts[$normalized] ?? 0);
            $isHomepage = $normalized === $this->normalizeUrl($preferredUrl);
            $reasons = [];
            if (!$inSitemap) {
                $reasons[] = 'missing_from_sitemap';
            }
            if (!$isHomepage && $inboundCount === 0) {
                $reasons[] = 'no_rendered_internal_links';
            }
            if ($reasons !== []) {
                $orphans[] = array_merge($important, [
                    "in_sitemap" => $inSitemap,
                    "rendered_inbound_link_count" => $inboundCount,
                    "reasons" => $reasons,
                ]);
            }
        }

        foreach ($audits as &$audit) {
            unset($audit['links']);
        }
        unset($audit);

        $summary = $this->summarizeAudits($audits);
        $issues = $this->siteIssues($state, $homepage, $robotsResponse, $robots, $manifest, $summary, $orphans, $preferredUrl);
        $hasError = false;
        foreach ($issues as $issue) {
            if (($issue['severity'] ?? '') === 'error') {
                $hasError = true;
                break;
            }
        }
        $complete = (bool) ($state['success'] ?? false)
            && (bool) ($manifest['complete'] ?? false)
            && (bool) ($homepage['success'] ?? false)
            && (bool) ($robotsResponse['success'] ?? false)
            && (int) ($summary['request_errors'] ?? 0) === 0;
        $technicalIndexable = $complete && !$hasError;
        $auditProofRows = array_map(static fn (array $audit): array => [
            "url" => (string) ($audit['url'] ?? ''),
            "final_url" => (string) ($audit['final_url'] ?? ''),
            "status_code" => $audit['status_code'] ?? null,
            "canonical_url" => (string) ($audit['canonical_url'] ?? ''),
            "indexable" => (bool) ($audit['indexable'] ?? false),
            "response_sha256" => (string) ($audit['response_sha256'] ?? ''),
            "reasons" => (array) ($audit['reasons'] ?? []),
        ], $audits);
        usort($auditProofRows, static fn (array $a, array $b): int => strcmp($a['url'], $b['url']));

        return [
            "success" => true,
            "complete" => $complete,
            "technical_indexable" => $technicalIndexable,
            "state" => !$complete ? 'incomplete' : ($technicalIndexable ? 'pass' : 'fail'),
            "message" => !$complete
                ? 'The site indexability scan is incomplete.'
                : ($technicalIndexable ? 'The scanned site and frozen sitemap manifest are technically indexable.' : 'Indexability blockers were found.'),
            "wordpress" => $state,
            "preferred_url" => $preferredUrl,
            "preferred_host" => $preferredHost,
            "homepage" => $this->withoutLinks($homepage),
            "robots_txt" => [
                "url" => $robotsUrl,
                "response" => $this->withoutLinks($robotsResponse),
                "sha256" => (string) ($robots['sha256'] ?? ''),
                "sitemaps" => array_values((array) ($robots['sitemaps'] ?? [])),
                "homepage_policy" => $this->robotsPolicy->allows($preferredUrl, $robots),
            ],
            "sitemaps" => $manifest,
            "not_found_probe" => $this->withoutLinks($notFoundProbe),
            "summary" => array_merge($summary, [
                "important_url_count" => count($importantUrls),
                "orphaned_important_url_count" => count($orphans),
            ]),
            "issues" => $issues,
            "orphaned_important_urls" => $orphans,
            "url_audits" => $audits,
            "proof" => [
                "generated_at" => now()->toIso8601String(),
                "scanner" => self::class,
                "target_url" => $siteUrl,
                "preferred_url" => $preferredUrl,
                "sitemap_manifest_sha256" => (string) ($manifest['manifest_sha256'] ?? ''),
                "sitemap_url_count" => (int) ($manifest['url_count'] ?? 0),
                "sitemap_manifest_complete" => (bool) ($manifest['complete'] ?? false),
                "audited_url_count" => count($audits),
                "url_audit_sha256" => hash('sha256', json_encode($auditProofRows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            ],
        ];
    }

    private function collectWordPressState(array $target): array
    {
        $php = <<<'PHP'
$modules=get_option("rank_math_modules", []);
if (is_string($modules)) { $modules=maybe_unserialize($modules); }
$modules=array_values(array_unique(array_map("strval", (array)$modules)));
$sitemapSettings=get_option("rank-math-options-sitemap", []);
if (is_string($sitemapSettings)) { $sitemapSettings=maybe_unserialize($sitemapSettings); }
$sitemapSettings=is_array($sitemapSettings) ? $sitemapSettings : [];
$titleSettings=get_option("rank-math-options-titles", []);
if (is_string($titleSettings)) { $titleSettings=maybe_unserialize($titleSettings); }
$titleSettings=is_array($titleSettings) ? $titleSettings : [];
$globalRobots=array_values(array_map("strval", (array)($titleSettings["robots_global"] ?? ["index","follow"])));
$robotSettings=[];
foreach ($titleSettings as $key=>$value) {
    if (stripos((string)$key, "robots") !== false || stripos((string)$key, "noindex") !== false) {
        $robotSettings[(string)$key]=$value;
    }
}
$postTypes=[];
foreach (get_post_types(["public"=>true], "objects") as $name=>$object) {
    $name=(string)$name;
    $count=wp_count_posts($name);
    $published=(int)($count->publish ?? 0) + (int)($count->inherit ?? 0);
    $sitemapKey="pt_".$name."_sitemap";
    $sitemapValue=(string)($sitemapSettings[$sitemapKey] ?? "on");
    $customRobots=(string)($titleSettings["pt_".$name."_custom_robots"] ?? "off");
    $typeRobots=array_values(array_map("strval", (array)($titleSettings["pt_".$name."_robots"] ?? [])));
    $postTypes[$name]=[
        "label"=>(string)($object->labels->name ?? $object->label ?? $name),
        "published_count"=>$published,
        "sitemap_setting_key"=>$sitemapKey,
        "sitemap_setting"=>$sitemapValue,
        "sitemap_included"=>$sitemapValue !== "off",
        "custom_robots"=>$customRobots,
        "robots"=>$customRobots === "on" ? $typeRobots : $globalRobots,
    ];
}
$important=[];
$addImportant=function($url, $label, $postId=0) use (&$important) {
    $url=(string)$url;
    if ($url !== "") $important[]=["url"=>$url,"label"=>(string)$label,"post_id"=>(int)$postId,"source"=>"wordpress"];
};
$addImportant(home_url("/"), "Homepage", (int)get_option("page_on_front"));
$postsPage=(int)get_option("page_for_posts");
if ($postsPage > 0) $addImportant(get_permalink($postsPage), "Posts page", $postsPage);
foreach (wp_load_alloptions() as $key=>$value) {
    if (strpos((string)$key, "sfpf_page_") === 0 && (int)$value > 0) {
        $addImportant(get_permalink((int)$value), ucwords(str_replace("_", " ", preg_replace("/^sfpf_page_/", "", (string)$key))), (int)$value);
    }
}
$rankMathActive=defined("RANK_MATH_VERSION") || class_exists("RankMath\\Plugin");
$rankMathSitemapEnabled=$rankMathActive && in_array("sitemap", $modules, true);
$expectedSitemaps=[$rankMathSitemapEnabled ? home_url("/sitemap_index.xml") : home_url("/wp-sitemap.xml")];
echo "HEXA_SITE_INDEXABILITY:" . wp_json_encode([
    "success"=>true,
    "home_url"=>home_url("/"),
    "site_url"=>site_url("/"),
    "blog_public"=>(int)get_option("blog_public", 1),
    "front_page_id"=>(int)get_option("page_on_front"),
    "posts_page_id"=>$postsPage,
    "rank_math"=>[
        "active"=>$rankMathActive,
        "modules"=>$modules,
        "sitemap_module_enabled"=>$rankMathSitemapEnabled,
        "sitemap_settings"=>$sitemapSettings,
        "global_robots"=>$globalRobots,
        "robots_settings"=>$robotSettings,
        "post_types"=>$postTypes,
    ],
    "expected_sitemap_urls"=>$expectedSitemaps,
    "important_urls"=>$important,
]);
PHP;

        $result = $this->wordpress->evaluatePhp($target, $php);
        if (!($result['success'] ?? false)) {
            return [
                "success" => false,
                "message" => (string) ($result['message'] ?? 'WordPress indexability settings could not be loaded.'),
                "home_url" => (string) ($target['url'] ?? ''),
            ];
        }

        $payload = $this->decodeMarkedPayload((string) ($result['stdout'] ?? ''), 'HEXA_SITE_INDEXABILITY:');
        if (!is_array($payload)) {
            return [
                "success" => false,
                "message" => 'WordPress indexability settings could not be parsed.',
                "home_url" => (string) ($target['url'] ?? ''),
            ];
        }

        return $payload;
    }

    private function assessUrl(
        string $url,
        array $response,
        array $robots,
        string $preferredHost,
        array $notFoundProbe,
        bool $fromSitemap,
        bool $important,
    ): array {
        $finalUrl = (string) (($response['final_url'] ?? '') ?: $url);
        $status = (int) ($response['status_code'] ?? 0);
        $robotsDecision = $this->robotsPolicy->allows($finalUrl, $robots);
        $canonical = (string) ($response['canonical_url'] ?? '');
        $canonicalSelf = $canonical === '' ? null : $this->normalizeUrl($canonical) === $this->normalizeUrl($finalUrl);
        $xRobots = implode(',', array_map('strval', (array) ($response['x_robots_tag'] ?? [])));
        $metaRobots = implode(',', array_map('strval', (array) ($response['meta_robots'] ?? [])));
        $noindex = $this->hasNoindex($xRobots) || $this->hasNoindex($metaRobots);
        $soft404 = $status === 200 && (
            preg_match('/(?:^|\b)(?:404|page not found|not found)(?:\b|$)/i', (string) ($response['title'] ?? '')) === 1
            || (
                (int) ($notFoundProbe['status_code'] ?? 0) === 200
                && (string) ($notFoundProbe['body_text_sha256'] ?? '') !== ''
                && hash_equals((string) $notFoundProbe['body_text_sha256'], (string) ($response['body_text_sha256'] ?? ''))
            )
        );
        $reasons = [];
        $warnings = [];

        if (!($response['success'] ?? false)) {
            $reasons[] = 'request_failed';
        }
        if ($status !== 200) {
            $reasons[] = 'http_' . ($status > 0 ? $status : 'error');
        }
        if ((bool) ($response['redirected'] ?? false) || $this->normalizeUrl($url) !== $this->normalizeUrl($finalUrl)) {
            $reasons[] = 'redirected_url';
        }
        if (!(bool) ($response['https'] ?? false)) {
            $reasons[] = 'not_https';
        }
        if ($preferredHost !== '' && strtolower((string) parse_url($finalUrl, PHP_URL_HOST)) !== $preferredHost) {
            $reasons[] = 'non_preferred_host';
        }
        if (!($robotsDecision['allowed'] ?? true)) {
            $reasons[] = 'robots_disallowed';
        }
        if ($noindex) {
            $reasons[] = 'noindex';
        }
        if ($canonicalSelf === false) {
            $reasons[] = 'canonical_points_elsewhere';
        } elseif ($canonicalSelf === null) {
            $warnings[] = 'canonical_missing';
        }
        if ($soft404) {
            $reasons[] = 'soft_404';
        }
        if ((bool) ($response['blocked'] ?? false)) {
            $reasons[] = 'access_blocked';
        }

        return [
            "url" => $url,
            "source" => ["sitemap" => $fromSitemap, "important" => $important],
            "final_url" => $finalUrl,
            "status_code" => $response['status_code'] ?? null,
            "redirected" => (bool) ($response['redirected'] ?? false),
            "redirect_history" => array_values((array) ($response['redirect_history'] ?? [])),
            "https" => (bool) ($response['https'] ?? false),
            "preferred_host" => $preferredHost === '' || strtolower((string) parse_url($finalUrl, PHP_URL_HOST)) === $preferredHost,
            "robots_allowed" => (bool) ($robotsDecision['allowed'] ?? true),
            "robots_rule" => $robotsDecision['matched_rule'] ?? null,
            "x_robots_tag" => array_values((array) ($response['x_robots_tag'] ?? [])),
            "meta_robots" => (array) ($response['meta_robots'] ?? []),
            "noindex" => $noindex,
            "canonical_url" => $canonical,
            "canonical_self" => $canonicalSelf,
            "soft_404" => $soft404,
            "blocked" => (bool) ($response['blocked'] ?? false),
            "block_reason" => (string) ($response['block_reason'] ?? ''),
            "indexable" => $reasons === [],
            "index_state" => $reasons === [] ? 'indexable' : 'excluded',
            "reasons" => array_values(array_unique($reasons)),
            "warnings" => array_values(array_unique($warnings)),
            "response_sha256" => (string) ($response['body_sha256'] ?? ''),
            "content_type" => (string) ($response['content_type'] ?? ''),
            "title" => (string) ($response['title'] ?? ''),
            "links" => array_values((array) ($response['links'] ?? [])),
            "fetched_at" => (string) ($response['fetched_at'] ?? now()->toIso8601String()),
            "error" => $response['error'] ?? null,
        ];
    }

    private function importantUrls(array $state, array $inventoryPages, array $configured): array
    {
        $items = [];
        foreach ((array) ($state['important_urls'] ?? []) as $item) {
            if (is_array($item)) {
                $items[] = $item;
            }
        }
        foreach ($inventoryPages as $page) {
            if (is_array($page) && (bool) ($page['is_critical'] ?? $page['critical'] ?? false)) {
                $items[] = [
                    "url" => (string) ($page['url'] ?? ''),
                    "label" => (string) (($page['critical_label'] ?? '') ?: ($page['title'] ?? 'Important page')),
                    "post_id" => (int) ($page['id'] ?? 0),
                    "source" => 'inventory',
                ];
            }
        }
        foreach ($configured as $key => $value) {
            $items[] = is_array($value)
                ? $value
                : ["url" => (string) $value, "label" => is_string($key) ? $key : 'Configured important URL', "source" => 'configured'];
        }

        $deduplicated = [];
        foreach ($items as $item) {
            $url = trim((string) ($item['url'] ?? ''));
            if (!$this->isHttpUrl($url)) {
                continue;
            }
            $normalized = $this->normalizeUrl($url);
            if (!isset($deduplicated[$normalized])) {
                $deduplicated[$normalized] = [
                    "url" => $url,
                    "label" => (string) ($item['label'] ?? 'Important URL'),
                    "post_id" => (int) ($item['post_id'] ?? 0),
                    "sources" => [(string) ($item['source'] ?? 'unknown')],
                ];
            } else {
                $deduplicated[$normalized]['sources'][] = (string) ($item['source'] ?? 'unknown');
                $deduplicated[$normalized]['sources'] = array_values(array_unique($deduplicated[$normalized]['sources']));
            }
        }

        return array_values($deduplicated);
    }

    private function inboundCounts(array $audits, string $preferredHost): array
    {
        $counts = [];
        foreach ($audits as $audit) {
            foreach ((array) ($audit['links'] ?? []) as $link) {
                if (strtolower((string) parse_url((string) $link, PHP_URL_HOST)) !== $preferredHost) {
                    continue;
                }
                $normalized = $this->normalizeUrl((string) $link);
                $counts[$normalized] = (int) ($counts[$normalized] ?? 0) + 1;
            }
        }

        return $counts;
    }

    private function summarizeAudits(array $audits): array
    {
        $summary = [
            "audited_urls" => count($audits),
            "indexable_urls" => 0,
            "excluded_urls" => 0,
            "sitemap_excluded_urls" => 0,
            "request_errors" => 0,
            "redirected_urls" => 0,
            "robots_blocked_urls" => 0,
            "noindex_urls" => 0,
            "canonical_conflicts" => 0,
            "missing_canonicals" => 0,
            "soft_404_urls" => 0,
            "access_blocked_urls" => 0,
            "non_https_urls" => 0,
            "non_preferred_host_urls" => 0,
        ];

        foreach ($audits as $audit) {
            $summary[(bool) ($audit['indexable'] ?? false) ? 'indexable_urls' : 'excluded_urls']++;
            if (!(bool) ($audit['indexable'] ?? false) && (bool) ($audit['source']['sitemap'] ?? false)) $summary['sitemap_excluded_urls']++;
            if (($audit['error'] ?? null) !== null) $summary['request_errors']++;
            if ((bool) ($audit['redirected'] ?? false)) $summary['redirected_urls']++;
            if (!(bool) ($audit['robots_allowed'] ?? true)) $summary['robots_blocked_urls']++;
            if ((bool) ($audit['noindex'] ?? false)) $summary['noindex_urls']++;
            if (($audit['canonical_self'] ?? null) === false) $summary['canonical_conflicts']++;
            if (($audit['canonical_self'] ?? null) === null) $summary['missing_canonicals']++;
            if ((bool) ($audit['soft_404'] ?? false)) $summary['soft_404_urls']++;
            if ((bool) ($audit['blocked'] ?? false)) $summary['access_blocked_urls']++;
            if (!(bool) ($audit['https'] ?? false)) $summary['non_https_urls']++;
            if (!(bool) ($audit['preferred_host'] ?? true)) $summary['non_preferred_host_urls']++;
        }

        return $summary;
    }

    private function siteIssues(
        array $state,
        array $homepage,
        array $robotsResponse,
        array $robots,
        array $manifest,
        array $summary,
        array $orphans,
        string $preferredUrl,
    ): array {
        $issues = [];
        $add = static function (string $severity, string $code, string $message, array $context = []) use (&$issues): void {
            $issues[] = compact('severity', 'code', 'message', 'context');
        };

        if (!($state['success'] ?? false)) {
            $add('error', 'wordpress_state_unavailable', (string) ($state['message'] ?? 'WordPress indexability settings are unavailable.'));
        }
        if ((int) ($state['blog_public'] ?? 1) !== 1) {
            $add('error', 'wordpress_discourages_search_engines', 'WordPress blog_public is disabled.');
        }
        if (!($homepage['success'] ?? false)) {
            $add('error', 'homepage_request_failed', 'The public homepage request failed.');
        } elseif ((bool) ($homepage['blocked'] ?? false)) {
            $add('error', 'homepage_access_blocked', 'The homepage appears to be blocked by maintenance, password protection, or a WAF.', ["reason" => $homepage['block_reason'] ?? '']);
        }
        if (strtolower((string) parse_url($preferredUrl, PHP_URL_SCHEME)) !== 'https') {
            $add('error', 'preferred_url_not_https', 'The final preferred homepage URL is not HTTPS.');
        }
        if (!($robotsResponse['success'] ?? false)) {
            $add('error', 'robots_request_failed', 'The public robots.txt request failed.');
        } elseif ((bool) ($robotsResponse['blocked'] ?? false)) {
            $add('error', 'robots_access_blocked', 'robots.txt appears to be blocked.');
        }
        $homepagePolicy = $this->robotsPolicy->allows($preferredUrl, $robots);
        if (!($homepagePolicy['allowed'] ?? true)) {
            $add('error', 'robots_blocks_homepage', 'robots.txt disallows Googlebot from the homepage.', ["rule" => $homepagePolicy['matched_rule'] ?? null]);
        }
        if ((int) ($manifest['url_count'] ?? 0) === 0) {
            $add('error', 'sitemap_empty', 'No crawlable URLs were found in the sitemap manifest.');
        }
        if (!($manifest['complete'] ?? false)) {
            $add('error', 'sitemap_manifest_incomplete', 'The nested sitemap manifest is incomplete.', ["errors" => $manifest['errors'] ?? []]);
        }
        if ((int) ($summary['sitemap_excluded_urls'] ?? 0) > 0) {
            $add('error', 'sitemap_urls_not_indexable', 'One or more frozen sitemap URLs are not technically indexable.', ["count" => (int) $summary['sitemap_excluded_urls']]);
        }
        if ($orphans !== []) {
            $add('error', 'important_urls_orphaned', 'One or more important URLs are missing from the sitemap or have no rendered internal links.', ["count" => count($orphans)]);
        }
        if ((int) ($summary['missing_canonicals'] ?? 0) > 0) {
            $add('warning', 'canonical_missing', 'One or more audited URLs do not render a canonical link.', ["count" => (int) $summary['missing_canonicals']]);
        }

        $rankMath = (array) ($state['rank_math'] ?? []);
        if (($rankMath['active'] ?? false) && !($rankMath['sitemap_module_enabled'] ?? false)) {
            $add('error', 'rank_math_sitemap_module_disabled', 'Rank Math is active but its sitemap module is disabled.');
        }
        if (in_array('noindex', array_map('strtolower', (array) ($rankMath['global_robots'] ?? [])), true)) {
            $add('error', 'rank_math_global_noindex', 'Rank Math global robots settings contain noindex.');
        }
        foreach ((array) ($rankMath['post_types'] ?? []) as $name => $postType) {
            if (is_array($postType) && (int) ($postType['published_count'] ?? 0) > 0 && !($postType['sitemap_included'] ?? true)) {
                $add('error', 'rank_math_content_type_excluded', "Published {$name} content is excluded from Rank Math sitemaps.", [
                    "post_type" => $name,
                    "published_count" => (int) ($postType['published_count'] ?? 0),
                ]);
            }
        }

        return $issues;
    }

    private function withoutLinks(array $response): array
    {
        unset($response['links']);

        return $response;
    }

    private function hasNoindex(string $directives): bool
    {
        return preg_match('/(?:^|[,\s:])(?:noindex|none)(?:[,\s]|$)/i', $directives) === 1;
    }

    private function decodeMarkedPayload(string $stdout, string $marker): ?array
    {
        $position = strrpos($stdout, $marker);
        if ($position === false) {
            return null;
        }

        $payload = json_decode(trim(substr($stdout, $position + strlen($marker))), true);

        return is_array($payload) ? $payload : null;
    }

    private function origin(string $url): string
    {
        $scheme = (string) (parse_url($url, PHP_URL_SCHEME) ?: 'https');
        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);

        return $scheme . '://' . $host . ($port ? ':' . $port : '');
    }

    private function normalizeUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || empty($parts['host'])) {
            return trim($url);
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) $parts['host']);
        $path = (string) ($parts['path'] ?? '/');
        $path = $path === '/' ? '/' : rtrim($path, '/');
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        return $scheme . '://' . $host . $path . $query;
    }

    private function isHttpUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true)
            && (string) parse_url($url, PHP_URL_HOST) !== '';
    }
}
