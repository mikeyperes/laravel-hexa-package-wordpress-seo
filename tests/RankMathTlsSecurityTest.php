<?php

namespace hexa_package_wordpress_seo\Tests;

use PHPUnit\Framework\TestCase;

final class RankMathTlsSecurityTest extends TestCase
{
    public function test_frontend_inventory_keeps_tls_certificate_verification_enabled(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__).'/src/Services/RankMathPageService.php');

        self::assertStringContainsString('wp_safe_remote_get', $source);
        self::assertStringContainsString('"sslverify"=>true', $source);
        self::assertStringNotContainsString('"sslverify"=>false', $source);
        self::assertStringContainsString('"limit_response_size"=>1048576', $source);
    }
}
