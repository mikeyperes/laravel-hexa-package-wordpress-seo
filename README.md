# laravel-hexa-package-wordpress-seo
Abstract WordPress SEO orchestration package for scanning, inventory, proposals, and provider-driven SEO writebacks.

## Site-level indexability

Default install scans include the `site_indexability` feature. The reusable `WordPressSiteIndexabilityService` freezes and hashes the site's complete nested sitemap manifest, then checks every frozen sitemap URL plus every configured or WordPress-discovered important URL.

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

Stored scans keep compact site-level proof on the scan target and, after the package migration is applied, one structured row per audited URL in `wordpress_seo_indexability_url_records`. `technical_indexable=true` means the complete frozen manifest passed these technical checks; it does not claim that Google has already indexed every URL.

## WHM cached account scopes

Do not rebuild WHM server/domain dropdowns inside WordPress SEO screens. Domain and cPanel-account lookup goes through:

```php
use hexa_package_wordpress_seo\Services\WordPressSeoDiscoveryService;

$discovery = app(WordPressSeoDiscoveryService::class);

$hits = $discovery->searchCachedDomains('example.com', 12);
$account = $discovery->resolveCachedAccount([
    'domain' => 'example.com',
], true);
$accounts = $discovery->cachedAccounts();
```

The discovery service delegates to `hexa_package_whm\Services\WhmService`, so cache TTLs, stale-server checks, and refresh leases remain centralized in the WHM package.

Supported scan scopes:

- `account`: scans every WordPress install under one cached cPanel account.
- `server`: scans cached active cPanel accounts for one WHM server.
- `all_servers`: scans cached active cPanel accounts across active WHM servers.
- `install` and `page`: legacy WP Toolkit install/page scopes for exact install IDs.

Page inventory includes every requested page/sub-page in the selected post types, hierarchy metadata, stored Rank Math title/description override fields, effective public frontend SEO title/description fallbacks, featured image metadata, and SFPF critical-page flags (`page_on_front`, `sfpf_page_*`, `book`, `organization`). Use `SeoProviderRegistry::get("rankmath")->writePage($target, $postId, [...])` for Rank Math title/description writebacks; use the WordPress package for post status/title/slug/featured-image/media mutations.

Default package scans include `page`, `post`, `book`, and `organization` post types with publish/draft/future/private/pending statuses, so callers do not silently miss sub-pages, posts, or SFPF critical CPTs. Callers may still pass narrower `post_types`/`statuses` filters when intentionally scoping a scan.


## Rank Math score, proposals, and internal links

The package is the source of truth for reusable SEO behavior:

- `SeoProviderRegistry::get("rankmath")->inventoryPages($target, $filters)` returns page inventory, Rank Math title/description, featured image metadata, `rank_math_seo_score`, focus keyword, canonical/robots metadata, raw page text/html, and detected internal links without rendering every page through `the_content`.
- `WordPressSeoProposalService::proposeField($input)` generates one generic field proposal. `proposeFields($input, $fields)` generates title, slug, Rank Math fields, featured-image URL, and image metadata proposals in one batch AI request for the requested fields. Rank Math title/description proposals include explicit package-owned length constraints and post-generation guards so returned values stay inside acceptable Rank Math ranges. Callers provide context; the service does not know about the calling app.
- `WordPressSeoInternalLinkService::analyze($page, $sitePages, $options)` evaluates page text against the loaded site page inventory and returns current internal links plus highlighted internal-link suggestions.
- `WordPressSeoScorePreviewService::analyze($page, $draft)` returns live field diagnostics for Rank Math title and description length, word counts, per-field preview scores, and an aggregate preview score. If stored Rank Math override fields are empty, the preview falls back to the effective public frontend title/description captured during inventory. Rank Math stores one post-level score in `rank_math_seo_score`; title and description field scores are package previews for live editing feedback, not separate WordPress meta fields.

App packages should not duplicate these primitives. App code should supply page/entity context, render controls, and call these package services.


## Version 0.1.29

- Added generic site-level indexability scanning, recursive sitemap freezing and hashing, complete sitemap URL inspection, Rank Math/WordPress indexability settings, soft-404 and access-block detection, important-URL orphan detection, and durable per-URL scan records.

## Version 0.1.25

- Changed `WordPressSeoProposalService::proposeFields()` to make one batch AI request for all requested fields instead of looping through one AI request per field.

## Version 0.1.24

- Added effective frontend SEO title/description fallbacks to Rank Math inventory normalization and score previews while preserving stored Rank Math override fields as distinct editable values.

## Version 0.1.22

- Fixed proposal field normalization so associative `field => label` config still supports canonical field IDs like `seo_title` and `seo_description`.

## Version 0.1.21

- Added Rank Math-aware AI proposal constraints for SEO titles and descriptions, including package-level output guards and returned field metrics.

## Version 0.1.20

- Added reusable Rank Math title/description live preview diagnostics with character counts, word counts, and field health scoring.

## Version 0.1.19

- Optimized Rank Math inventory scans so dashboard consumers can load scores and SEO metadata without rendering every post through WordPress content filters.
