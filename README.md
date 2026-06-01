# laravel-hexa-package-wordpress-seo
Abstract WordPress SEO orchestration package for scanning, inventory, proposals, and provider-driven SEO writebacks.

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

Page inventory includes every requested page/sub-page in the selected post types, hierarchy metadata, Rank Math title/description fields, featured image metadata, and SFPF critical-page flags (`page_on_front`, `sfpf_page_*`, `book`, `organization`). Use `SeoProviderRegistry::get("rankmath")->writePage($target, $postId, [...])` for Rank Math title/description writebacks; use the WordPress package for post status/title/slug/featured-image/media mutations.

Default package scans include `page`, `post`, `book`, and `organization` post types with publish/draft/future/private/pending statuses, so callers do not silently miss sub-pages, posts, or SFPF critical CPTs. Callers may still pass narrower `post_types`/`statuses` filters when intentionally scoping a scan.
