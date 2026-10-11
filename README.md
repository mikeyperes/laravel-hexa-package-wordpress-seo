# laravel-hexa-package-wordpress-seo

Live, read-mostly SEO view of one WordPress site for the HWS SEO skills.
Every call reads the site directly; nothing is stored.

## Services

- `WordPressSeoDiscoveryService::resolveInstallTarget($domain)`: the exact WP Toolkit installation for a domain (also `searchCachedDomains` and `resolveCachedDomain` through the WHM package's cache).
- `RankMathPageService::inventoryPages($target, $filters)`: every page's Rank Math title, description, focus keyword, score, robots, canonical and featured image, read in batches until the whole site is loaded, plus the served title, description and headings of its published pages, fetched in parallel by Code.
- `PageStructureCheck::check($page, $siteSlugs)`: the H1, heading-order and slug checks shown on each `pages` line. `writePage($target, $postId, [...])` writes one page's Rank Math title and description; `inspect($target)` reports the Rank Math plugins.
- `WordPressSiteIndexabilityService::scan($target, $pages)`: the indexability scan below.
- `WordPressSeoInternalLinkService::analyze($page, $sitePages)`: one page's current internal links and suggestions.
- `WordPressImageMetaService`: one image's alt text, title, caption, description, file name, size and format.

## Site-level indexability

The reusable `WordPressSiteIndexabilityService` freezes and hashes the site's complete nested sitemap manifest, then checks every frozen sitemap URL plus every configured or WordPress-discovered important URL.

```php
use hexa_package_wordpress_seo\Services\WordPressSiteIndexabilityService;

$report = app(WordPressSiteIndexabilityService::class)->scan(
    $wordpressTarget,
    $pageInventory,
    [
        'sitemap_urls' => [], // Empty discovers from robots.txt and the active WordPress provider.
        'important_urls' => ['https://example.com/about/'],
    ],
);
```

The report distinguishes scan completion from technical indexability. It includes:

- WordPress `blog_public`, Rank Math module/global robots state, and sitemap inclusion for every public content type.
- Public `robots.txt`, redirect history, final HTTPS host, `X-Robots-Tag`, rendered meta robots, and rendered canonicals.
- Maintenance, password, WAF/challenge, hard-error, and soft-404 evidence.
- Recursive sitemap documents, a SHA-256 frozen URL manifest, and one URL audit receipt per manifest URL.
- Important-URL sitemap membership and rendered internal-link orphan detection.

Nothing is stored. `technical_indexable=true` means the complete frozen manifest passed these technical checks; it does not claim that Google has already indexed every URL.

## Command line

`php artisan wordpress-seo:site <domain> [pages|indexability|links] [--page=<id>]`
prints JSON for one site, read-only:

- `pages` — every page (all post types and statuses, loaded in batches of
  `inventory.per_page` until the whole site is read): Rank Math title,
  description, focus keyword, score, robots, canonical, featured image and alt,
  the title and description visitors are actually served, and three checks
  from the served page: `h1` (exactly one H1), `heading_order` (no skipped
  levels inside the main content) and `slug_check` (a WordPress "-2" duplicate,
  underscores, capitals or encoded characters). Code reads the served HTML of up to `inventory.served_limit`
  published pages (100 by default), at most 2 at a time and 1 second apart,
  with cached pages allowed; `served_checked` says how many. A check is
  `null` when that page was not read.
- `indexability` — the site indexability scan (blog visibility, robots.txt,
  noindex, canonicals, redirects, sitemaps, orphan pages).
- `links --page=<id>` — internal-link suggestions for one page and its dead
  links (status 0 or 400+).

## Image SEO fields

`php artisan wordpress-seo:image <domain> <attachment-id|image-url>` reads one image's alt text, title, caption, description, file name, size and format. Add `--alt= --title= --caption= --description=` to write them through WordPress's own functions, and `--post=<id>` to also refresh that image's alt inside one post's content. Service: `WordPressImageMetaService`.

## Version 0.4.2

- SEO-BUG-001: the page scanner is throttled to 2 requests at a time, 1 second apart, and no longer bypasses the cache; the `pages` view reads 100 served pages by default.

## Version 0.4.1

- Served pages are fetched by Code instead of one at a time inside the WordPress command, which WP Toolkit stopped after 120 seconds on larger sites; batches are 200 pages.
- Added the H1, heading-order and slug checks to every `pages` line.

## Version 0.4.0

- Removed the stored SEO dashboard: scans, scan targets, page records, proposals, execution runs, activity logs, the background scan runner, the field-length preview and the AI proposal writer (the last two moved to the SFPF person-audit package, their only user). Their seven tables are dropped by migration.
- Replaced the one-provider registry and interface with `RankMathPageService`.
