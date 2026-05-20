<?php

namespace hexa_package_wordpress_seo\Services;

use hexa_package_whm\Models\WhmServer;
use hexa_package_wordpress\Services\WordPressManagerService;
use hexa_package_wptoolkit\Services\WpToolkitService;
use Illuminate\Support\Collection;

class WordPressSeoDiscoveryService
{
    public function __construct(
        protected WordPressManagerService $wp,
        protected WpToolkitService $wptoolkit,
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
