<?php

namespace hexa_package_wordpress_seo\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use hexa_package_wordpress_seo\Services\Indexability\PublicUrlInspector;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

/**
 * Read-only link scan of one web page (a live page or a saved HTML draft):
 * dead internal links, empty links, jump links to spots that do not exist,
 * buttons that do nothing, clickable-looking text that is not a link, web
 * addresses typed as plain text, and the site's own page list (sitemap).
 */
final class PageLinkScanner
{
    private const USER_AGENT = 'Mozilla/5.0 (HWS page-link-integrity)';
    private const SKIP_PATHS = ['/cdn-cgi/'];
    private const CTA_WORDS = '/^(learn more|read more|see more|view( all| more)?|see all|explore|get started|start now|book (a call|now|online|a table|tickets)|contact us|get in touch|discover|details(?=\s*$)|more info|shop now|watch|apply|sign up|join)\b(?!\s*:)/i';
    private const CLICKY_CLASS = '/(^|[\s_-])(btn|button|cta|card|tile|link)([\s_-]|$)/i';
    private const WEB_ADDRESS = '/\b(?:https?:\/\/|www\.)[^\s<>"\']+[^\s<>"\'.,;:!?)]/i';
    private const HEADINGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'title'];
    private const TEXT_TAGS = ['li', 'strong', 'b', 'em', 'i', 'u', 'label', 'dt', 'dd', 'td', 'th'];
    private const NO_TEXT = ['script', 'style', 'noscript', 'textarea', 'code', 'pre', 'head'];
    private const CONTAINERS = ['html', 'body', 'section', 'main', 'div', 'ul', 'nav', 'header', 'footer'];
    private const LAYOUT_DATA = ['data-id', 'data-element_type', 'data-e-type', 'data-widget_type', 'data-settings', 'data-rv', 'data-anim', 'data-no-lazy'];

    private array $ids = [];
    private array $anchors = [];
    private array $buttons = [];
    private array $clicky = [];
    private array $addresses = [];

    public function __construct(
        private readonly HttpFactory $http,
        private readonly PublicUrlInspector $inspector,
    ) {
    }

    /** @return array<string, mixed> */
    public function scan(string $pageUrl, ?string $html = null, bool $checkStatus = true): array
    {
        $html ??= $this->fetch($pageUrl);
        $analysis = $this->analyze($pageUrl, $html);

        $dead = [];
        if ($checkStatus && $analysis['internal_pages'] !== []) {
            $checks = $this->inspector->inspectMany(array_column($analysis['internal_pages'], 'url'), (array) config('wordpress-seo.indexability', []));
            foreach ($analysis['internal_pages'] as &$item) {
                $item['status'] = (int) ($checks[$item['url']]['status_code'] ?? 0);
                if ($item['status'] === 0 || $item['status'] >= 400) {
                    $dead[] = $item;
                }
            }
            unset($item);
            usort($dead, fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        }

        $analysis['counts']['dead'] = count($dead);
        $parts = parse_url($pageUrl);

        return [
            'page' => $pageUrl,
            'counts' => $analysis['counts'],
            'dead_links' => $dead,
            'dummy_links' => $analysis['dummy_links'],
            'missing_jump_targets' => $analysis['missing_jump_targets'],
            'buttons_to_check' => $analysis['buttons_to_check'],
            'clickable_looking_not_linked' => $analysis['clickable_looking_not_linked'],
            'addresses_not_linked' => $analysis['addresses_not_linked'],
            'internal_pages' => $analysis['internal_pages'],
            'site_pages' => $this->sitemapPages(($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '')),
        ];
    }

    /**
     * Everything that needs only the page's HTML (no network).
     *
     * @return array<string, mixed>
     */
    public function analyze(string $pageUrl, string $html): array
    {
        $this->ids = $this->anchors = $this->buttons = $this->clicky = $this->addresses = [];

        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $this->walk($doc, false, false, false);

        $parts = parse_url($pageUrl);
        $host = $this->bare($parts['host'] ?? '');
        $path = rtrim($parts['path'] ?? '', '/');
        $dummy = $jumps = $internal = $external = [];

        foreach ($this->anchors as $a) {
            if ($a['href'] === null && $a['target']) {
                continue; // a named jump spot (<a name> or <a id>), not a link
            }
            $href = trim((string) ($a['href'] ?? ''));
            if ($href === '' || $href === '#' || str_starts_with(strtolower($href), 'javascript')) {
                if ($a['role'] !== 'button') {
                    $dummy[] = ['text' => $a['text'], 'href' => $a['href']];
                }
                continue;
            }
            if (preg_match('/^(mailto|tel|sms):/i', $href)) {
                continue;
            }
            $absolute = $this->resolve($pageUrl, $href);
            $u = parse_url($absolute) ?: [];
            $same = $this->bare($u['host'] ?? '') === $host;
            $fragment = $u['fragment'] ?? '';
            if ($fragment !== '' && $same && rtrim($u['path'] ?? '', '/') === $path) {
                if (!isset($this->ids[$fragment]) && $fragment !== 'top') {
                    $jumps[] = ['text' => $a['text'], 'href' => $href];
                }
                continue;
            }
            if (!$same) {
                $external[$absolute] = true;
                continue;
            }
            $linkPath = $u['path'] ?? '/';
            foreach (self::SKIP_PATHS as $skip) {
                if (str_starts_with($linkPath, $skip)) {
                    continue 2;
                }
            }
            $key = ($u['scheme'] ?? 'https') . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '') . ($linkPath === '' ? '/' : $linkPath) . (isset($u['query']) ? '?' . $u['query'] : '');
            $internal[$key] ??= ['url' => $key, 'count' => 0, 'texts' => []];
            $internal[$key]['count']++;
            if ($a['text'] !== '') {
                $internal[$key]['texts'][mb_substr($a['text'], 0, 40)] = true;
            }
        }

        foreach ($internal as &$item) {
            $texts = array_keys($item['texts']);
            sort($texts);
            $item['texts'] = array_slice($texts, 0, 4);
            $item['status'] = null;
        }
        unset($item);
        ksort($internal);

        $buttons = array_values(array_filter($this->buttons, fn (array $b): bool => !$b['in_link'] && !$b['onclick'] && !$b['controls'] && !in_array($b['type'], ['submit', 'reset'], true)));
        $clicky = $this->outermost();
        $addresses = array_values(array_unique($this->addresses));
        sort($addresses);

        return [
            'counts' => [
                'links' => count($this->anchors),
                'internal_pages' => count($internal),
                'external_links' => count($external),
                'dead' => 0,
                'dummy' => count($dummy),
                'missing_jump_targets' => count($jumps),
                'buttons_to_check' => count($buttons),
                'clickable_looking' => count($clicky),
                'addresses_not_linked' => count($addresses),
            ],
            'dummy_links' => $dummy,
            'missing_jump_targets' => $jumps,
            'buttons_to_check' => $buttons,
            'clickable_looking_not_linked' => array_slice($clicky, 0, 60),
            'addresses_not_linked' => $addresses,
            'internal_pages' => array_values($internal),
        ];
    }

    /**
     * Walks the page children-first, so an outer element is recorded after
     * the elements inside it. Returns [text, contains link or button, contains heading].
     *
     * @return array{0: string, 1: bool, 2: bool}
     */
    private function walk(DOMNode $node, bool $inLink, bool $inCrumb, bool $noText): array
    {
        $text = '';
        $hasLink = false;
        $hasHeading = false;

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $text .= $child->data;
                if (!$inLink && !$noText) {
                    preg_match_all(self::WEB_ADDRESS, $child->data, $m);
                    array_push($this->addresses, ...$m[0]);
                }
                continue;
            }
            if (!$child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            $class = $child->getAttribute('class');
            if ($child->hasAttribute('id') && $child->getAttribute('id') !== '') {
                $this->ids[$child->getAttribute('id')] = true;
            }
            if ($tag === 'a' && $child->getAttribute('name') !== '') {
                $this->ids[$child->getAttribute('name')] = true;
            }
            $crumb = $inCrumb || str_contains(strtolower($class . ' ' . $child->getAttribute('aria-label')), 'breadcrumb');
            [$childText, $childLink, $childHeading] = $this->walk($child, $inLink || $tag === 'a', $crumb, $noText || in_array($tag, self::NO_TEXT, true));
            $childText = $this->clean($childText);
            $isLink = $tag === 'a' || $tag === 'button';
            $isHeading = in_array($tag, self::HEADINGS, true) || str_contains($class, 'elementor-heading-title');

            if ($tag === 'a') {
                $this->anchors[] = ['href' => $child->hasAttribute('href') ? $child->getAttribute('href') : null, 'text' => mb_substr($childText, 0, 80), 'role' => $child->getAttribute('role') ?: null, 'target' => $child->getAttribute('name') !== '' || $child->getAttribute('id') !== ''];
            } elseif ($tag === 'button') {
                $this->buttons[] = [
                    'text' => mb_substr($childText, 0, 80) ?: $child->getAttribute('aria-label'),
                    'type' => $child->getAttribute('type') ?: null,
                    'onclick' => $child->getAttribute('onclick') !== '',
                    'in_link' => $inLink,
                    'controls' => $child->getAttribute('aria-controls') ?: $this->scriptAction($child),
                ];
            } elseif ($this->looksClickable($tag, $child, $childText, $inLink || $childLink, $childHeading, $crumb)) {
                $this->clicky[] = ['tag' => $tag, 'text' => $childText, 'class' => mb_substr($class, 0, 60)];
            }

            $text .= ' ' . $childText . ' ';
            $hasLink = $hasLink || $isLink || $childLink;
            $hasHeading = $hasHeading || $isHeading || $childHeading;
        }

        return [$text, $hasLink, $hasHeading];
    }

    private function looksClickable(string $tag, DOMElement $el, string $text, bool $linked, bool $heading, bool $crumb): bool
    {
        if ($linked || $heading || $crumb || in_array($tag, self::HEADINGS, true) || str_contains($el->getAttribute('class'), 'elementor-heading-title')) {
            return false;
        }
        if ($text === '' || mb_strlen($text) > 40 || str_ends_with($text, ':')) {
            return false;
        }
        $cta = (bool) preg_match(self::CTA_WORDS, $text);
        $styled = (bool) preg_match(self::CLICKY_CLASS, $el->getAttribute('class'));
        if (!(($cta && !in_array($tag, self::TEXT_TAGS, true) && count(preg_split('/\s+/', $text)) <= 4) || $styled)) {
            return false;
        }
        if (in_array($tag, self::CONTAINERS, true) && !$cta) {
            return false;
        }

        return $el->getAttribute('role') !== 'button' && $el->getAttribute('onclick') === '';
    }

    /** A button with its own data setting (a video id, a target) is wired up by the page's scripts. */
    private function scriptAction(DOMElement $el): ?string
    {
        foreach ($el->attributes as $attr) {
            if (str_starts_with($attr->name, 'data-') && !in_array($attr->name, self::LAYOUT_DATA, true) && $attr->value !== '') {
                return $attr->name . '=' . mb_substr($attr->value, 0, 40);
            }
        }

        return null;
    }

    /** One entry per text (the outer element, recorded last, wins), without button labels. */
    private function outermost(): array
    {
        $labels = array_flip(array_map(fn (array $b): string => (string) $b['text'], $this->buttons));
        $keep = [];
        foreach ($this->clicky as $item) {
            if (!isset($labels[$item['text']])) {
                unset($keep[$item['text']]);
                $keep[$item['text']] = $item;
            }
        }

        return array_values($keep);
    }

    /** @return list<string> */
    public function sitemapPages(string $root): array
    {
        $pages = $seen = [];
        $queue = [$root . '/sitemap_index.xml', $root . '/wp-sitemap.xml', $root . '/sitemap.xml'];
        while ($queue !== [] && count($seen) < 40) {
            $url = array_shift($queue);
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            try {
                $body = $this->fetch($url);
            } catch (Throwable) {
                continue;
            }
            preg_match_all('/<loc>\s*([^<\s]+)\s*<\/loc>/', $body, $m);
            foreach ($m[1] as $loc) {
                $last = substr($loc, (int) strrpos($loc, '/') + 1);
                if (str_ends_with($loc, '.xml') || str_contains($last, 'sitemap')) {
                    $queue[] = $loc;
                } else {
                    $pages[$loc] = true;
                }
            }
            if ($pages !== [] && $queue === []) {
                break;
            }
        }
        $pages = array_keys($pages);
        sort($pages);

        return $pages;
    }

    private function fetch(string $url): string
    {
        $response = $this->http->withHeaders(['User-Agent' => self::USER_AGENT])->connectTimeout(5)->timeout(25)->get($url);
        if ($response->failed()) {
            throw new \RuntimeException('HTTP ' . $response->status() . ' for ' . $url);
        }

        return $response->body();
    }

    private function resolve(string $base, string $href): string
    {
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $href)) {
            return $href;
        }
        $b = parse_url($base);
        $origin = ($b['scheme'] ?? 'https') . '://' . ($b['host'] ?? '') . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($href, '//')) {
            return ($b['scheme'] ?? 'https') . ':' . $href;
        }
        if (str_starts_with($href, '#')) {
            return $origin . ($b['path'] ?? '/') . (isset($b['query']) ? '?' . $b['query'] : '') . $href;
        }
        if (str_starts_with($href, '?')) {
            return $origin . ($b['path'] ?? '/') . $href;
        }
        if (str_starts_with($href, '/')) {
            return $origin . $this->dotSegments($href);
        }
        $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');

        return $origin . $this->dotSegments($dir . $href);
    }

    private function dotSegments(string $path): string
    {
        [$p, $rest] = array_pad(preg_split('/(?=[?#])/', $path, 2), 2, '');
        $out = [];
        foreach (explode('/', $p) as $seg) {
            if ($seg === '..') {
                array_pop($out);
            } elseif ($seg !== '.') {
                $out[] = $seg;
            }
        }
        $joined = implode('/', $out);

        return (str_starts_with($joined, '/') ? '' : '/') . $joined . $rest;
    }

    private function bare(string $host): string
    {
        $host = strtolower($host);

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    private function clean(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
