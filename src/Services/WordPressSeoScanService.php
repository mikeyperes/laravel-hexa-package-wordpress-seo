<?php

namespace hexa_package_wordpress_seo\Services;

use hexa_package_whm\Models\WhmServer;

class WordPressSeoScanService
{
    public function __construct(
        protected WordPressSeoDiscoveryService $discovery,
        protected SeoProviderRegistry $providers,
        protected SupplementalUrlContextService $urlContext,
    ) {
    }

    public function scanAllServers(string $providerKey = "rankmath", array $features = [], array $filters = []): array
    {
        $sites = [];
        foreach ($this->discovery->servers() as $server) {
            $sites = array_merge($sites, $this->scanServer($server, $providerKey, $features, $filters)["sites"]);
        }

        return [
            "success" => true,
            "scope" => "all_servers",
            "provider" => $providerKey,
            "sites" => $sites,
        ];
    }

    public function scanServer(WhmServer $server, string $providerKey = "rankmath", array $features = [], array $filters = []): array
    {
        $sites = [];
        foreach ($this->discovery->installsForServer($server) as $install) {
            $target = $this->discovery->normalizeInstallTarget($server, $install);
            $sites[] = $this->scanInstall($target, $providerKey, $features, $filters);
        }

        return [
            "success" => true,
            "scope" => "server",
            "server_id" => $server->id,
            "provider" => $providerKey,
            "sites" => $sites,
        ];
    }

    public function scanInstall(array $target, string $providerKey = "rankmath", array $features = [], array $filters = []): array
    {
        $provider = $this->providers->get($providerKey);
        $plugin = $provider->inspect($target);
        $inventory = $provider->inventoryPages($target, $filters);

        return [
            "success" => (bool) ($inventory["success"] ?? false),
            "scope" => "install",
            "target" => $target,
            "provider" => $providerKey,
            "plugin" => $plugin,
            "features" => $features !== [] ? array_values($features) : array_values((array) config("wordpress-seo.supported_features", [])),
            "pages" => array_values($inventory["pages"] ?? []),
            "message" => (string) ($inventory["message"] ?? $plugin["message"] ?? ""),
        ];
    }

    public function scanPage(array $target, int $pageId, string $providerKey = "rankmath"): array
    {
        $provider = $this->providers->get($providerKey);
        $page = $provider->readPage($target, $pageId);
        $urls = $this->urlContext->detectUrls((string) (($page["page"]["content_text"] ?? "") . "\n" . ($page["page"]["excerpt"] ?? "")));
        $extracted = $urls !== [] ? $this->urlContext->extractMany($urls) : [];

        return [
            "success" => (bool) ($page["success"] ?? false),
            "scope" => "page",
            "target" => $target,
            "provider" => $providerKey,
            "page" => $page["page"] ?? null,
            "detected_urls" => $urls,
            "extracted_urls" => $extracted,
            "message" => (string) ($page["message"] ?? ""),
        ];
    }
}
