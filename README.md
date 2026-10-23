# laravel-hexa-package-wordpress-seo

Live, read-mostly SEO view of one WordPress site for the HWS SEO skills.
Every call reads the site directly; nothing is stored.

## Services

- `WordPressSeoDiscoveryService::resolveInstallTarget($domain)`: the exact WP Toolkit installation for a domain (also `searchCachedDomains` and `resolveCachedDomain` through the WHM package's cache).
- `RankMathPageService::inventoryPages($target, $filters)`: every page's Rank Math title, description, focus keyword, score, robots, canonical and featured image, read in batches until the whole site is loaded, plus the served title, description and headings of its published pages, fetched in parallel by Code.
- `PageStructureCheck::check($page, $siteSlugs)`: the H1, heading-order and slug checks shown on each `pages` line. `writePage($target, $postId, [...])` writes one page's Rank Math title and description; `inspect($target)` reports the Rank Math plugins.
- `WordPressSiteIndexabilityService::scan($target, $pages)`: the indexability scan below.
- `WordPressSeoInternalLinkService::analyze($page, $sitePages)`: one page's current internal links and suggestions.
- `PageLinkScanner::scan($url, $html = null, $checkStatus = true)`: every link on one page (live page or saved HTML draft), sorted into dead, empty, jump links to nothing, buttons and clickable-looking text that go nowhere, plain-text web addresses, plus the site's sitemap page list. `analyze($url, $html)` is the same without any network calls.
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

## llms.txt

`php artisan wordpress-seo:llms-txt <domain> [status|apply|off]` runs Rank
Math's built-in llms.txt. `apply` switches the module on and fills it in: the
content types and categories the Rank Math sitemap includes (or
`--post-types=` / `--taxonomies=`), `llms_txt.limit` links per type,
`--site-name=` (Rank Math's site name, the file's heading and its structured
data name), `--summary=` (the line under the heading) and `--extra-file=`. It
clears LiteSpeed's copy of `/llms.txt` and checks the served file; `status`
lists what is still missing (placeholder site name, no summary, file not
served, an `llms.txt` file on the server overriding Rank Math).

## robots.txt

`php artisan wordpress-seo:robots-txt <domain> [status|plan|apply] [--block=<path>]... [--unblock=<path>]...`
keeps robots.txt in Rank Math's robots.txt setting. The built file names every
crawler in `robots_txt.ai_crawlers` beside `*` so AI search crawlers are
allowed on purpose and follow the same rules, adds `robots_txt.base_rules`,
the pages blocked on request (read from the saved rules, never a cached copy)
and Rank Math's sitemap line, which Rank Math's custom text would otherwise
drop. `apply` removes an old `robots.txt` file on the server that overrides
Rank Math, clears LiteSpeed's copy and checks what the site serves; a cache in
front of the site, such as Cloudflare, is reported separately. `status` also
fetches the homepage as each AI crawler in `robots_txt.ai_fetch_agents` to
catch a firewall block. `apply` refuses a site set to hide itself from search
engines.

## Image SEO fields

`php artisan wordpress-seo:image <domain> <attachment-id|image-url>` reads one image's alt text, title, caption, description, file name, size and format. Add `--alt= --title= --caption= --description=` to write them through WordPress's own functions, and `--post=<id>` to also refresh that image's alt inside one post's content. Service: `WordPressImageMetaService`.

## Featured images folder

`php artisan wordpress-seo:configure` shows the settings; `--featured-images-folder=<Drive folder URL>` saves the Google Drive parent folder (Hexa Core setting `wordpress_seo.featured_images_parent_url`). Each site's approved featured images go in a `<domain>` folder inside it, added one file at a time with `google-drive:create`. Share the parent folder with Code's Drive identities (`google-drive:access <folder>` checks them).

`php artisan wordpress-seo:page-links <page URL> [--html=<file>] [--no-status]`
prints JSON for one page, read-only (used by the page-link-integrity skill):
`dead_links` (internal pages that answer 404, another error or nothing, checked
at most 2 at a time and 1 second apart), `dummy_links` (no address, `#` or
`javascript:`), `missing_jump_targets` (`#name` links with no matching spot),
`buttons_to_check` (buttons with no link, click handler, controlled element or
script data), `clickable_looking_not_linked`, `addresses_not_linked`,
`internal_pages` and `site_pages`. `--html` scans a rendered or draft page saved
to a file; `--no-status` skips the dead-link checks. Works on any website, not
only WordPress.

## Version 0.8.0

- `wordpress-seo:page-links` and `PageLinkScanner`: the one-page link scan, moved here from the page-link-integrity skill's own script. Named jump spots (`<a name>` with no address) are no longer reported as empty links.

## Version 0.7.0

- `wordpress-seo:llms-txt`: switch on, fill in and check Rank Math's llms.txt.
- `wordpress-seo:robots-txt`: build robots.txt in Rank Math with AI crawlers allowed, block and unblock pages, take over from an old server file.
- The robots.txt reader no longer ends a rule group at a comment line.

## Version 0.5.1

- The page list covers published pages only by default (drafts, scheduled, private and pending pages were included before).
- A site found through WP Toolkit now keeps its cPanel account and WordPress folder, so login links and file paths resolve.

## Version 0.5.0

- `wordpress-seo:configure` and the featured images parent folder setting.

## Version 0.4.2

- SEO-BUG-001: the page scanner is throttled to 2 requests at a time, 1 second apart, and no longer bypasses the cache; the `pages` view reads 100 served pages by default.

## Version 0.4.1

- Served pages are fetched by Code instead of one at a time inside the WordPress command, which WP Toolkit stopped after 120 seconds on larger sites; batches are 200 pages.
- Added the H1, heading-order and slug checks to every `pages` line.

## Version 0.4.0

- Removed the stored SEO dashboard: scans, scan targets, page records, proposals, execution runs, activity logs, the background scan runner, the field-length preview and the AI proposal writer (the last two moved to the SFPF person-audit package, their only user). Their seven tables are dropped by migration.
- Replaced the one-provider registry and interface with `RankMathPageService`.
