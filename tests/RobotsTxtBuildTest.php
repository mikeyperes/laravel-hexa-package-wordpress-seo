<?php

namespace hexa_package_wordpress_seo\Tests;

use hexa_package_wordpress_seo\Services\Indexability\RobotsTxtPolicy;
use hexa_package_wordpress_seo\Services\RobotsTxtService;
use Tests\TestCase;

final class RobotsTxtBuildTest extends TestCase
{
    public function test_built_file_allows_search_and_ai_crawlers_and_keeps_blocks_and_sitemap(): void
    {
        $content = app(RobotsTxtService::class)->build(['/private-page/'], 'https://example.com/sitemap_index.xml');
        $policy = (new RobotsTxtPolicy())->parse($content);

        self::assertSame(['https://example.com/sitemap_index.xml'], $policy['sitemaps']);
        foreach (['Googlebot', 'GPTBot', 'OAI-SearchBot', 'ClaudeBot', 'PerplexityBot', 'CCBot'] as $agent) {
            self::assertTrue((new RobotsTxtPolicy())->allows('https://example.com/', $policy, $agent)['allowed'], $agent);
            self::assertFalse((new RobotsTxtPolicy())->allows('https://example.com/private-page/', $policy, $agent)['allowed'], $agent);
            self::assertFalse((new RobotsTxtPolicy())->allows('https://example.com/wp-admin/', $policy, $agent)['allowed'], $agent);
            self::assertTrue((new RobotsTxtPolicy())->allows('https://example.com/wp-admin/admin-ajax.php', $policy, $agent)['allowed'], $agent);
        }
    }

    public function test_comment_lines_do_not_end_a_rule_group(): void
    {
        $policy = (new RobotsTxtPolicy())->parse("User-agent: *\nDisallow: /a/\n# note\nDisallow: /b/\n");

        self::assertCount(1, $policy['groups']);
        self::assertFalse((new RobotsTxtPolicy())->allows('https://example.com/b/', $policy)['allowed']);
    }
}
