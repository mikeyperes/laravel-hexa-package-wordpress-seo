<?php

namespace hexa_package_wordpress_seo\Services;

use hexa_package_whm\Models\WhmServer;
use hexa_package_whm\Services\WhmService;
use hexa_package_wordpress\Services\WordPressManagerService;
use hexa_package_wptoolkit\Services\WpToolkitService;

/**
 * Resolves a domain to its exact WP Toolkit installation target on the
 * WHM server that hosts it.
 */
class WordPressSeoDiscoveryService
{
    public function __construct(
        protected WordPressManagerService $wp,
        protected WpToolkitService $wptoolkit,
        protected WhmService $whm,
    ) {
    }

    public function installsForServer(WhmServer $server): array
    {
        $result = $this->wptoolkit->getAllInstalls($server);
        return (bool) ($result["success"] ?? false) ? array_values($result["installs"] ?? []) : [];
    }

    public function searchCachedDomains(string $query, int $limit = 15, array $options = []): array
    {
        return $this->whm->searchCachedDomains($query, $limit, $options);
    }

    public function resolveCachedDomain(string $domain, array $options = []): array
    {
        return $this->whm->resolveCachedDomain($domain, $options);
    }

    /** Resolve one domain to its exact WP Toolkit installation target, or null when there is no single match. */
    public function resolveInstallTarget(string $domain): ?array
    {
        $host = strtolower(preg_replace("#^www\\.#", "", (string) (parse_url("https://" . preg_replace("#^https?://#", "", trim($domain)), PHP_URL_HOST) ?? "")));
        $resolved = $this->resolveCachedDomain($host);
        $server = WhmServer::find((int) ($resolved["account"]["server_id"] ?? 0));
        if ($host === "" || !$server) {
            return null;
        }

        $matches = array_values(array_filter($this->installsForServer($server), function (array $install) use ($host): bool {
            $url = (string) ($install["url"] ?? "");
            $installHost = strtolower(preg_replace("#^www\\.#", "", (string) parse_url($url, PHP_URL_HOST)));

            return $installHost === $host && in_array((string) parse_url($url, PHP_URL_PATH), ["", "/"], true);
        }));

        return count($matches) === 1 ? $this->normalizeInstallTarget($server, $matches[0]) : null;
    }

    public function normalizeInstallTarget(WhmServer $server, array $install, string $defaultAuthor = ""): array
    {
        return $this->wp->normalizeTarget([
            "mode" => "wptoolkit",
            "site_id" => null,
            "site_name" => (string) ($install["name"] ?? $install["url"] ?? "WordPress site"),
            "url" => (string) ($install["url"] ?? ""),
            "username" => "",
            "application_password" => "",
            "server" => $server,
            "install_id" => (int) ($install["id"] ?? 0),
            "default_author" => $defaultAuthor,
        ]);
    }
}
