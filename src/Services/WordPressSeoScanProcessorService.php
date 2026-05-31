<?php

namespace hexa_package_wordpress_seo\Services;

use hexa_package_whm\Models\WhmServer;
use hexa_package_wordpress_seo\Models\SeoScan;
use RuntimeException;
use Throwable;

class WordPressSeoScanProcessorService
{
    public function __construct(
        protected WordPressSeoDiscoveryService $discovery,
        protected WordPressSeoScanService $scanService,
        protected SeoScanStoreService $store,
    ) {
    }

    public function process(SeoScan $scan): array
    {
        $processedTargets = 0;
        $processedPages = 0;

        do {
            $result = $this->processNextChunk($scan, 10);
            $scan->refresh();
            $processedTargets = (int) (($scan->summary ?? [])["processed_targets"] ?? 0);
            $processedPages = (int) (($scan->summary ?? [])["processed_pages"] ?? 0);
        } while (
            in_array((string) $scan->status, ["queued", "running"], true)
            && (
                ((int) ($result["processed_now"] ?? 0) > 0)
                || ((bool) ($result["initialized"] ?? false))
            )
        );

        return [
            "success" => (string) $scan->status === "completed",
            "processed_targets" => $processedTargets,
            "processed_pages" => $processedPages,
            "status" => (string) $scan->status,
        ];
    }

    public function processNextChunk(SeoScan $scan, int $chunkSize = 1): array
    {
        $scan->refresh();

        if (in_array((string) $scan->status, ["completed", "failed"], true)) {
            return [
                "success" => (string) $scan->status === "completed",
                "status" => (string) $scan->status,
                "processed_now" => 0,
                "initialized" => false,
            ];
        }

        $initialized = false;
        $meta = (array) ($scan->meta ?? []);

        if (!isset($meta["queue"]) || !is_array($meta["queue"])) {
            $meta["queue"] = $this->buildQueue($scan);
            $meta["cursor"] = 0;
            $meta["queue_total"] = count((array) $meta["queue"]);
            $initialized = true;
        }

        if ((string) $scan->status === "queued") {
            $scan->status = "running";
            $scan->started_at ??= now();
        }

        $providerKey = (string) ($scan->provider_key ?: config("wordpress-seo.default_provider", "rankmath"));
        $featureSet = array_values((array) ($scan->feature_set ?: config("wordpress-seo.supported_features", [])));
        $queue = array_values(array_filter((array) ($meta["queue"] ?? []), "is_array"));
        $total = count($queue);
        $cursor = (int) ($meta["cursor"] ?? 0);
        $processedTargets = (int) (($scan->summary ?? [])["processed_targets"] ?? 0);
        $processedPages = (int) (($scan->summary ?? [])["processed_pages"] ?? 0);

        if ($cursor >= $total) {
            return $this->markCompleted($scan, $meta, $providerKey, $processedTargets, $processedPages);
        }

        try {
            $slice = array_slice($queue, $cursor, max(1, $chunkSize));
            $processedNow = 0;
            $latestTarget = null;

            foreach ($slice as $item) {
                $results = $this->scanQueueItem($scan, $providerKey, $featureSet, $item);
                $processedNow++;

                foreach ($results as $result) {
                    $target = $this->store->storeInstallResult($scan, $result);
                    $latestTarget = $target;
                    $processedTargets++;
                    $processedPages += count((array) ($result["pages"] ?? []));
                }
            }

            $cursor += $processedNow;
            $meta["cursor"] = $cursor;
            $meta["queue_total"] = $total;
            $scan->meta = $meta;
            $summary = [
                "scope_type" => (string) $scan->scope_type,
                "provider_key" => $providerKey,
                "processed_targets" => $processedTargets,
                "processed_pages" => $processedPages,
            ];
            if ($latestTarget) {
                $summary["latest_target_id"] = $latestTarget->id;
                $summary["latest_site_name"] = $latestTarget->site_name;
            }
            $scan->summary = $summary;
            $scan->save();

            if ($cursor >= $total) {
                return $this->markCompleted($scan, $meta, $providerKey, $processedTargets, $processedPages);
            }

            return [
                "success" => true,
                "status" => (string) $scan->status,
                "processed_now" => $processedNow,
                "initialized" => $initialized,
                "processed_targets" => $processedTargets,
                "processed_pages" => $processedPages,
                "remaining" => max(0, $total - $cursor),
            ];
        } catch (Throwable $error) {
            return $this->markFailed($scan, $providerKey, $processedTargets, $processedPages, $error);
        }
    }

    protected function buildQueue(SeoScan $scan): array
    {
        $scopeType = (string) $scan->scope_type;
        $scopePayload = (array) ($scan->scope_payload ?? []);
        $queue = [];

        if ($scopeType === "all_servers") {
            return $this->accountQueueItems($this->discovery->cachedAccounts());
        }

        $serverId = (int) ($scopePayload["server_id"] ?? 0);

        if ($scopeType === "server") {
            if (!WhmServer::query()->whereKey($serverId)->exists()) {
                throw new RuntimeException("Scan server was not found.");
            }

            return $this->accountQueueItems($this->discovery->cachedAccounts($serverId));
        }

        if ($scopeType === "account") {
            $account = $this->discovery->resolveCachedAccount($scopePayload);
            if (!$account) {
                throw new RuntimeException("Cached cPanel account was not found for the selected domain.");
            }

            return $this->accountQueueItems([$account]);
        }

        $server = WhmServer::query()->find($serverId);
        if (!$server) {
            throw new RuntimeException("Scan server was not found.");
        }

        $installId = (int) ($scopePayload["install_id"] ?? 0);
        if ($installId <= 0) {
            throw new RuntimeException("WordPress install is required for this scan scope.");
        }

        $options = [];
        if ($scopeType === "page") {
            $pageId = (int) ($scopePayload["page_id"] ?? 0);
            if ($pageId <= 0) {
                throw new RuntimeException("Page ID is required for page scans.");
            }

            $options = [
                "page_id" => $pageId,
                "per_page" => 1,
            ];
        }

        $queue[] = [
            "server_id" => (int) $server->id,
            "install_id" => $installId,
            "options" => $options,
        ];

        return $queue;
    }

    protected function scanQueueItem(SeoScan $scan, string $providerKey, array $featureSet, array $item): array
    {
        $server = WhmServer::query()->find((int) ($item["server_id"] ?? 0));
        if (!$server) {
            throw new RuntimeException("Scan server was not found.");
        }

        if ((string) ($item["type"] ?? "install") === "account") {
            return $this->scanAccountQueueItem($server, $providerKey, $featureSet, $item);
        }

        $installId = (int) ($item["install_id"] ?? 0);
        $install = $this->resolveInstall($server, $installId);
        if (!$install) {
            throw new RuntimeException("WordPress install was not found on the selected server.");
        }

        $options = (array) ($item["options"] ?? []);
        $target = $this->discovery->normalizeInstallTarget($server, $install);

        return [$this->scanService->scanInstall($target, $providerKey, $featureSet, $options)];
    }

    protected function accountQueueItems(array $accounts): array
    {
        $queue = [];
        $seen = [];

        foreach ($accounts as $account) {
            $serverId = (int) ($account["server_id"] ?? $account["whm_server_id"] ?? 0);
            $username = trim((string) ($account["username"] ?? $account["name"] ?? ""));
            if ($serverId <= 0 || $username === "") {
                continue;
            }

            $key = $serverId . ":" . strtolower($username);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $queue[] = [
                "type" => "account",
                "server_id" => $serverId,
                "cpanel_username" => $username,
                "domain" => (string) ($account["domain"] ?? ""),
                "matched_domain" => (string) ($account["matched_domain"] ?? ""),
                "options" => [],
            ];
        }

        return $queue;
    }

    protected function scanAccountQueueItem(WhmServer $server, string $providerKey, array $featureSet, array $item): array
    {
        $username = trim((string) ($item["cpanel_username"] ?? ""));
        if ($username === "") {
            return [$this->accountFailureResult($server, $item, $providerKey, $featureSet, "Cached cPanel account is missing a username.")];
        }

        $installs = $this->discovery->installsForAccount($server, $username);
        if ($installs === []) {
            return [$this->accountFailureResult($server, $item, $providerKey, $featureSet, "No WordPress installs were found for {$username}.")];
        }

        $results = [];
        foreach ($installs as $install) {
            $target = $this->discovery->normalizeInstallTarget($server, $install);
            $results[] = $this->scanService->scanInstall($target, $providerKey, $featureSet, (array) ($item["options"] ?? []));
        }

        return $results;
    }

    protected function accountFailureResult(WhmServer $server, array $item, string $providerKey, array $featureSet, string $message): array
    {
        $account = [
            "username" => (string) ($item["cpanel_username"] ?? ""),
            "domain" => (string) ($item["domain"] ?? ""),
            "matched_domain" => (string) ($item["matched_domain"] ?? ""),
        ];

        return [
            "success" => false,
            "scope" => "account",
            "target" => $this->discovery->normalizeAccountTarget($server, $account),
            "provider" => $providerKey,
            "plugin" => [
                "success" => false,
                "installed" => false,
                "active" => false,
                "message" => $message,
            ],
            "features" => $featureSet,
            "pages" => [],
            "message" => $message,
            "meta" => [
                "queue_item" => $item,
            ],
        ];
    }

    protected function markCompleted(SeoScan $scan, array $meta, string $providerKey, int $processedTargets, int $processedPages): array
    {
        $scan->status = "completed";
        $scan->completed_at = now();
        $meta["cursor"] = (int) ($meta["queue_total"] ?? count((array) ($meta["queue"] ?? [])));
        $scan->meta = $meta;
        $scan->summary = [
            "scope_type" => (string) $scan->scope_type,
            "provider_key" => $providerKey,
            "processed_targets" => $processedTargets,
            "processed_pages" => $processedPages,
        ];
        $scan->save();

        $this->store->recordActivity([
            "seo_scan_id" => $scan->id,
            "event" => "scan.completed",
            "message" => "WordPress SEO scan completed.",
            "context" => [
                "processed_targets" => $processedTargets,
                "processed_pages" => $processedPages,
            ],
        ]);

        return [
            "success" => true,
            "status" => "completed",
            "processed_now" => 0,
            "initialized" => false,
            "processed_targets" => $processedTargets,
            "processed_pages" => $processedPages,
        ];
    }

    protected function markFailed(SeoScan $scan, string $providerKey, int $processedTargets, int $processedPages, Throwable $error): array
    {
        $scan->status = "failed";
        $scan->completed_at = now();
        $scan->summary = [
            "scope_type" => (string) $scan->scope_type,
            "provider_key" => $providerKey,
            "processed_targets" => $processedTargets,
            "processed_pages" => $processedPages,
            "error" => $error->getMessage(),
        ];
        $scan->save();

        $this->store->recordActivity([
            "seo_scan_id" => $scan->id,
            "level" => "error",
            "event" => "scan.failed",
            "message" => $error->getMessage(),
            "context" => [
                "processed_targets" => $processedTargets,
                "processed_pages" => $processedPages,
            ],
        ]);

        return [
            "success" => false,
            "status" => "failed",
            "processed_now" => 0,
            "initialized" => false,
            "processed_targets" => $processedTargets,
            "processed_pages" => $processedPages,
            "error" => $error->getMessage(),
        ];
    }

    protected function resolveInstall(WhmServer $server, int $installId): ?array
    {
        foreach ($this->discovery->installsForServer($server) as $install) {
            if ((int) ($install["id"] ?? 0) === $installId) {
                return $install;
            }
        }

        return null;
    }
}
