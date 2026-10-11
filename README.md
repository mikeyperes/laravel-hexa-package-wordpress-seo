# laravel-hexa-package-wordpress-seo

Live, read-mostly SEO view of one WordPress site for the HWS SEO skills.
Every call reads the site directly; nothing is stored.

## Services

- `WordPressSeoDiscoveryService::resolveInstallTarget($domain)`: the exact WP Toolkit installation for a domain (also `searchCachedDomains` and `resolveCachedDomain` through the WHM package's cache).
- `RankMathPageService::inventoryPages($target, $filters)`: every page's Rank Math title, description, focus keyword, score, robots, canonical, featured image and the title and description visitors are served, read in batches until the whole site is loaded. `writePage($target, $postId, [...])` writes one page's Rank Math title and description; `inspect($target)` reports the Rank Math plugins.
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
  and the title/description visitors are actually served (checked for up to
  `inventory.effective_frontend_limit` published pages; `served_checked` says
  how many).
- `indexability` — the site indexability scan (blog visibility, robots.txt,
  noindex, canonicals, redirects, sitemaps, orphan pages).
- `links --page=<id>` — internal-link suggestions for one page and its dead
  links (status 0 or 400+).

## Image SEO fields

`php artisan wordpress-seo:image <domain> <attachment-id|image-url>` reads one image's alt text, title, caption, description, file name, size and format. Add `--alt= --title= --caption= --description=` to write them through WordPress's own functions, and `--post=<id>` to also refresh that image's alt inside one post's content. Service: `WordPressImageMetaService`.

## Version 0.4.0

- Removed the stored SEO dashboard: scans, scan targets, page records, proposals, execution runs, activity logs, the background scan runner, the field-length preview and the AI proposal writer (the last two moved to the SFPF person-audit package, their only user). Their seven tables are dropped by migration.
- Replaced the one-provider registry and interface with `RankMathPageService`.
