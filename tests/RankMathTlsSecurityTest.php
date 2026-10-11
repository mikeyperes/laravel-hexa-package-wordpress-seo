<?php

namespace hexa_package_wordpress_seo\Tests;

use PHPUnit\Framework\TestCase;

final class RankMathTlsSecurityTest extends TestCase
{
    public function test_served_page_fetch_keeps_tls_certificate_verification_enabled(): void
    {
        $inspector = (string) file_get_contents(dirname(__DIR__).'/src/Services/Indexability/PublicUrlInspector.php');
        $pages = (string) file_get_contents(dirname(__DIR__).'/src/Services/RankMathPageService.php');

        self::assertStringContainsString("'verify' => true", $inspector);
        self::assertStringNotContainsString("'verify' => false", $inspector);
        self::assertStringContainsString('$this->inspector->inspectMany(', $pages);
        self::assertStringNotContainsString('sslverify', $pages);
    }
}
