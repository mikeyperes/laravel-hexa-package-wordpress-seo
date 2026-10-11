# WordPress SEO Bug Log

## SEO-BUG-001 — The page scanner flooded sites on its own server

- **Severity:** High
- **Status:** Fixed in 0.4.2.
- **Symptom:** On 2026-10-11 (02:42–02:53 UTC) michaelperes.com received up to
  1,190 scanner requests a minute (`HWS-IndexabilityScanner/1.0`) from server
  236 itself; the account used about 10 cores of PHP and the server load rose
  to 23 (normal about 7). A full herforward.com page read (02:41–02:46 UTC)
  sent 996 requests the same way.
- **Root cause:** `PublicUrlInspector` fetched 8 pages at a time with no pause
  and sent `Cache-Control: no-cache`, so LiteSpeed rendered every page in PHP.
  0.4.1 also made the `pages` view read up to 1,000 served pages per run.
- **Patch:** At most 2 requests at a time with at least 1 second between rounds
  (hard cap in code, `indexability.concurrency` / `delay_ms` in config); the
  cache-bypass header is removed, so cached pages are served from cache; the
  `pages` view reads 100 served pages by default.
- **Guard:** `CRITICAL — see BUGLOG.md SEO-BUG-001` in `PublicUrlInspector::inspectMany()`
  and `config/wordpress-seo.php`.
