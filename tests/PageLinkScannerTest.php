<?php

namespace hexa_package_wordpress_seo\Tests;

use hexa_package_wordpress_seo\Services\PageLinkScanner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class PageLinkScannerTest extends TestCase
{
    private const PAGE = <<<'HTML'
<html><head><title>Home</title><script>var u = "https://in-script.example.com";</script></head>
<body>
<nav class="breadcrumbs"><a href="/">Home</a> <span class="link">About us</span></nav>
<h2 class="elementor-heading-title">Learn more about us</h2>
<section id="team">
  <a href="/about/">About</a>
  <a href="https://www.example.com/about/">About again</a>
  <a href="/missing-page/">Our services</a>
  <a href="../contact/?x=1">Contact</a>
  <a href="#team">Jump ok</a>
  <a href="#nowhere">Jump bad</a>
  <a href="#top">Top</a>
  <a href="">Empty</a>
  <a href="#">Hash</a>
  <a href="javascript:void(0)">Script</a>
  <a href="#" role="button">Menu toggle</a>
  <a href="mailto:a@example.com">Mail</a>
  <a href="tel:123">Call</a>
  <a href="https://other.com/page">Partner</a>
  <a href="/cdn-cgi/l/email-protection">protected</a>
  <a name="anchor-name"></a><a href="#anchor-name">Named</a>
</section>
<button>Do nothing</button>
<button type="submit">Send</button>
<button onclick="go()">Has click</button>
<button aria-controls="menu">Opens menu</button>
<button data-video="abc123" data-id="9">Play</button>
<a href="/about/"><button>Inside link</button></a>
<div><span class="card-title">Leadership</span></div>
<div class="card"><span>Plain card</span></div>
<p>Read more</p>
<div><span>Get started</span></div>
<li>Learn more</li>
<span class="btn" role="button">Role button</span>
<span class="cta">Label:</span>
<p>Visit www.plain-address.com today or https://typed.example.org/path.</p>
<p><a href="https://linked.example.net">https://linked.example.net</a></p>
<code>https://in-code.example.com</code>
</body></html>
HTML;

    private function scanner(): PageLinkScanner
    {
        return app(PageLinkScanner::class);
    }

    public function test_empty_jump_and_internal_links_are_sorted_correctly(): void
    {
        $r = $this->scanner()->analyze('https://example.com/blog/post/', self::PAGE);

        self::assertSame(['Empty', 'Hash', 'Script'], array_column($r['dummy_links'], 'text'));
        self::assertSame(['Jump bad'], array_column($r['missing_jump_targets'], 'text'));

        $urls = array_column($r['internal_pages'], 'url');
        self::assertSame([
            'https://example.com/',
            'https://example.com/about/',
            'https://example.com/blog/contact/?x=1',
            'https://example.com/missing-page/',
            'https://www.example.com/about/',
        ], $urls, 'the www address is checked as written, but still counts as this site');
        $about = $r['internal_pages'][1];
        self::assertSame(2, $about['count']);
        self::assertSame(2, $r['counts']['external_links'], 'other.com and linked.example.net');
        self::assertNotContains('https://example.com/cdn-cgi/l/email-protection', $urls);
    }

    public function test_buttons_that_do_nothing_are_listed_and_wired_ones_are_not(): void
    {
        $r = $this->scanner()->analyze('https://example.com/', self::PAGE);

        self::assertSame(['Do nothing'], array_column($r['buttons_to_check'], 'text'));
    }

    public function test_clickable_looking_text_skips_headings_breadcrumbs_text_tags_and_labels(): void
    {
        $texts = array_column($this->scanner()->analyze('https://example.com/', self::PAGE)['clickable_looking_not_linked'], 'text');

        self::assertContains('Leadership', $texts);
        self::assertContains('Read more', $texts);
        self::assertContains('Get started', $texts);
        self::assertSame(1, count(array_keys($texts, 'Get started')), 'one entry per text');
        self::assertNotContains('Learn more about us', $texts, 'headings are names, not links');
        self::assertNotContains('About us', $texts, 'breadcrumb end is plain text');
        self::assertNotContains('Learn more', $texts, 'list items are content');
        self::assertNotContains('Role button', $texts);
        self::assertNotContains('Plain card', $texts, 'a styled container is not flagged unless it reads like a call to action');
        self::assertNotContains('Label:', $texts);
    }

    public function test_plain_text_addresses_skip_links_and_code(): void
    {
        $r = $this->scanner()->analyze('https://example.com/', self::PAGE);

        self::assertSame(['https://typed.example.org/path', 'www.plain-address.com'], $r['addresses_not_linked']);
    }

    public function test_scan_marks_dead_links_and_reads_the_sitemap(): void
    {
        Http::fake([
            'example.com/missing-page/' => Http::response('', 404),
            'example.com/sitemap_index.xml' => Http::response('<sitemapindex><sitemap><loc>https://example.com/page-sitemap.xml</loc></sitemap></sitemapindex>'),
            'example.com/page-sitemap.xml' => Http::response('<urlset><url><loc>https://example.com/about/</loc></url><url><loc>https://example.com/</loc></url></urlset>'),
            'example.com/*' => Http::response('ok', 200),
        ]);

        $r = $this->scanner()->scan('https://example.com/', self::PAGE);

        self::assertSame(['https://example.com/missing-page/'], array_column($r['dead_links'], 'url'));
        self::assertSame(404, $r['dead_links'][0]['status']);
        self::assertSame(1, $r['counts']['dead']);
        self::assertSame(['https://example.com/', 'https://example.com/about/'], $r['site_pages']);
    }

    public function test_no_status_skips_link_checks(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $r = $this->scanner()->scan('https://example.com/', self::PAGE, false);

        self::assertSame([], $r['dead_links']);
        self::assertNull($r['internal_pages'][0]['status']);
    }

    public function test_command_reads_a_saved_html_file_and_prints_json(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        $file = tempnam(sys_get_temp_dir(), 'plk');
        file_put_contents($file, self::PAGE);

        $code = Artisan::call('wordpress-seo:page-links', ['url' => 'https://example.com/', '--html' => $file, '--no-status' => true]);
        $out = json_decode(Artisan::output(), true);
        unlink($file);

        self::assertSame(0, $code);
        self::assertTrue($out['success']);
        self::assertSame(3, $out['counts']['dummy']);
    }

    public function test_command_rejects_a_bad_address(): void
    {
        $code = Artisan::call('wordpress-seo:page-links', ['url' => 'example.com']);

        self::assertSame(1, $code);
        self::assertFalse(json_decode(Artisan::output(), true)['success']);
    }
}
