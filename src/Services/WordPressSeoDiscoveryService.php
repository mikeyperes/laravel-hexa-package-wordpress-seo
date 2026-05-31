<?php

namespace hexa_package_wordpress_seo\Services;

use hexa_package_whm\Models\WhmServer;
use hexa_package_whm\Services\WhmService;
use hexa_package_wordpress\Services\WordPressManagerService;
use hexa_package_wptoolkit\Services\WpToolkitService;
use Illuminate\Support\Collection;

class WordPressSeoDiscoveryService
{
    public function __construct(
        protected WordPressManagerService $wp,
        protected WpToolkitService $wptoolkit,
        protected WhmService $whm,
    ) {
    }

    public function servers(): Collection
    {
        return WhmServer::query()->where("is_active", true)->orderBy("name")->get();
    }

    public function installsForServer(WhmServer $server): array
    {
        $result = $this->wptoolkit->getAllInstalls($server);
        return (bool) ($result["success"] ?? false) ? array_values($result["installs"] ?? []) : [];
    }

    public function installsForAccount(WhmServer $server, string $cpanelUsername): array
    {
        $result = $this->wp->discoverInstallsForAccount($server, $cpanelUsername);
        return (bool) ($result["success"] ?? false) ? array_values($result["installs"] ?? []) : [];
    }

    public function cachedDomainIndex(array $options = []): array
    {
        return $this->whm->cachedDomainIndex($options);
    }

    public function searchCachedDomains(string $query, int $limit = 15, array $options = []): array
    {
        return $this->whm->searchCachedDomains($query, $limit, $options);
    }

    public function resolveCachedDomain(string $domain, array $options = []): array
    {
        return $this->whm->resolveCachedDomain($domain, $options);
    }

    public function cachedAccounts(?int $serverId = null, array $options = []): array
    {
        if ($serverId !== null && $serverId > 0) {
            $options["server_id"] = $serverId;
        }

        $index = $this->cachedDomainIndex($options);

        return array_values(array_filter((array) ($index["accounts"] ?? []), "is_array"));
    }

    public function resolveCachedAccount(array $payload, bool $refreshOnMiss = true): ?array
    {
        $domain = trim((string) ($payload["domain"] ?? $payload["matched_domain"] ?? ""));
        $username = trim((string) ($payload["cpanel_username"] ?? $payload["username"] ?? ""));
        $serverId = (int) ($payload["server_id"] ?? 0);

        if ($domain !== "") {
            $resolved = $this->resolveCachedDomain($domain, [
                "server_id" => $serverId > 0 ? $serverId : null,
                "refresh_on_miss" => $refreshOnMiss,
            ]);

            if (($resolved["success"] ?? false) && is_array($resolved["account"] ?? null)) {
                return (array) $resolved["account"];
            }
        }

        if ($username === "") {
            return null;
        }

        foreach ($this->cachedAccounts($serverId > 0 ? $serverId : null) as $account) {
            if (strcasecmp((string) ($account["username"] ?? ""), $username) === 0) {
                return $account;
            }
        }

        return null;
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

    public function normalizeAccountTarget(WhmServer $server, array $account): array
    {
        $domain = trim((string) ($account["matched_domain"] ?? $account["domain"] ?? ""));
        $url = $domain !== "" ? "https://" . preg_replace("#^https?://#", "", $domain) : "";

        return $this->wp->normalizeTarget([
            "mode" => "wptoolkit",
            "site_id" => null,
            "site_name" => (string) ($account["domain"] ?? $account["username"] ?? "cPanel account"),
            "url" => rtrim($url, "/"),
            "username" => (string) ($account["username"] ?? ""),
            "application_password" => "",
            "server" => $server,
            "install_id" => null,
            "default_author" => "",
        ]);
    }

    public function loginLinks(WhmServer $server, array $install): array
    {
        $wp = null;
        $cpanel = null;

        $cpanelUser = (string) ($install["cpanel_user"] ?? "");
        $wpPath = (string) ($install["path"] ?? "");
        $siteUrl = (string) ($install["url"] ?? "");
        $wpUser = (string) ($install["admin_user"] ?? "");

        if ($cpanelUser !== "" && $wpPath !== "" && $wpUser !== "" && $siteUrl !== "") {
            $wp = $this->wptoolkit->generateWordPressLoginUrl($server, $wpPath, $cpanelUser, $wpUser, $siteUrl);
        }

        if ($cpanelUser !== "") {
            $cpanel = $this->wptoolkit->generateCpanelLoginUrl($server, $cpanelUser);
        }

        return [
            "wordpress" => $wp,
            "cpanel" => $cpanel,
        ];
    }
}
