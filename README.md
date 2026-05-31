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

Page inventory includes Rank Math title/description fields and featured image metadata for the Page Titles and Descriptions review card.
