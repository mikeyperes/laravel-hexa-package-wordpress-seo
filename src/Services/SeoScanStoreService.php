<?php

namespace hexa_package_wordpress_seo\Services;

use hexa_package_wordpress_seo\Models\SeoActivityLog;
use hexa_package_wordpress_seo\Models\SeoPageRecord;
use hexa_package_wordpress_seo\Models\SeoScan;
use hexa_package_wordpress_seo\Models\SeoScanTarget;

class SeoScanStoreService
{
    public function createScan(array $attributes = []): SeoScan
    {
        return SeoScan::create([
            "scope_type" => (string) ($attributes["scope_type"] ?? "install"),
            "scope_payload" => (array) ($attributes["scope_payload"] ?? []),
            "provider_key" => (string) ($attributes["provider_key"] ?? "rankmath"),
            "feature_set" => array_values((array) ($attributes["feature_set"] ?? config("wordpress-seo.supported_features", []))),
            "status" => (string) ($attributes["status"] ?? "queued"),
            "summary" => (array) ($attributes["summary"] ?? []),
            "meta" => (array) ($attributes["meta"] ?? []),
            "started_at" => $attributes["started_at"] ?? null,
            "completed_at" => $attributes["completed_at"] ?? null,
        ]);
    }

    public function storeInstallResult(SeoScan $scan, array $siteResult): SeoScanTarget
    {
        $targetPayload = (array) ($siteResult["target"] ?? []);
        $target = SeoScanTarget::create([
            "seo_scan_id" => $scan->id,
            "scope_type" => (string) ($siteResult["scope"] ?? "install"),
            "target_key" => $this->targetKey($targetPayload),
            "target_payload" => $targetPayload,
            "provider_key" => (string) ($siteResult["provider"] ?? $scan->provider_key ?? "rankmath"),
            "site_name" => (string) ($targetPayload["site_name"] ?? ""),
            "site_url" => (string) ($targetPayload["url"] ?? ""),
            "server_name" => (string) (($targetPayload["server"]["name"] ?? null) ?: ($targetPayload["server_name"] ?? "")),
            "cpanel_username" => (string) ($targetPayload["username"] ?? ""),
            "plugin_state" => (array) ($siteResult["plugin"] ?? []),
            "status" => (bool) ($siteResult["success"] ?? false) ? "scanned" : "failed",
            "summary" => [
                "page_count" => count((array) ($siteResult["pages"] ?? [])),
                "message" => (string) ($siteResult["message"] ?? ""),
            ],
            "meta" => (array) ($siteResult["meta"] ?? []),
            "started_at" => now(),
            "completed_at" => now(),
        ]);

        $this->syncPageRecords($target, (array) ($siteResult["pages"] ?? []));

        $this->recordActivity([
            "seo_scan_id" => $scan->id,
            "seo_scan_target_id" => $target->id,
            "event" => "scan.site.stored",
            "message" => (string) ($siteResult["message"] ?? "Install scan result stored."),
            "context" => [
                "site_name" => $target->site_name,
                "site_url" => $target->site_url,
                "page_count" => count((array) ($siteResult["pages"] ?? [])),
            ],
        ]);

        return $target;
    }

    public function syncPageRecords(SeoScanTarget $target, array $pages): array
    {
        $records = [];

        foreach ($pages as $page) {
            if (!is_array($page)) {
                continue;
            }

            $pageId = isset($page["id"]) ? (int) $page["id"] : null;
            $currentPayload = [
                "title" => (string) ($page["title"] ?? ""),
                "excerpt" => (string) ($page["excerpt"] ?? ""),
                "content_text" => (string) ($page["content_text"] ?? ""),
                "seo_title" => (string) ($page["seo_title"] ?? ""),
                "seo_description" => (string) ($page["seo_description"] ?? ""),
                "featured_image" => (string) ($page["featured_image"] ?? $page["featured_image_url"] ?? ""),
                "featured_image_url" => (string) ($page["featured_image_url"] ?? $page["featured_image"] ?? ""),
                "featured_image_alt" => (string) ($page["featured_image_alt"] ?? ""),
                "featured_image_id" => (int) ($page["featured_image_id"] ?? 0),
            ];

            $record = SeoPageRecord::updateOrCreate(
                [
                    "seo_scan_target_id" => $target->id,
                    "external_page_id" => $pageId,
                ],
                [
                    "page_type" => (string) ($page["post_type"] ?? ""),
                    "page_status" => (string) ($page["status"] ?? ""),
                    "title" => (string) ($page["title"] ?? ""),
                    "slug" => (string) ($page["slug"] ?? ""),
                    "permalink" => (string) ($page["url"] ?? ""),
                    "modified_gmt" => (string) ($page["modified_gmt"] ?? ""),
                    "current_payload" => $currentPayload,
                    "review_state" => "pending",
                    "inventory_hash" => sha1(json_encode($page)),
                    "meta" => (array) ($page["meta"] ?? []),
                ]
            );

            $records[] = $record;
        }

        return $records;
    }

    public function recordActivity(array $attributes): SeoActivityLog
    {
        return SeoActivityLog::create([
            "seo_scan_id" => $attributes["seo_scan_id"] ?? null,
            "seo_scan_target_id" => $attributes["seo_scan_target_id"] ?? null,
            "seo_page_record_id" => $attributes["seo_page_record_id"] ?? null,
            "seo_execution_run_id" => $attributes["seo_execution_run_id"] ?? null,
            "level" => (string) ($attributes["level"] ?? "info"),
            "event" => (string) ($attributes["event"] ?? "log"),
            "message" => (string) ($attributes["message"] ?? ""),
            "context" => (array) ($attributes["context"] ?? []),
            "created_at" => $attributes["created_at"] ?? now(),
        ]);
    }

    protected function targetKey(array $targetPayload): string
    {
        return sha1(json_encode([
            "url" => (string) ($targetPayload["url"] ?? ""),
            "install_id" => (int) ($targetPayload["install_id"] ?? 0),
            "site_name" => (string) ($targetPayload["site_name"] ?? ""),
            "server_id" => (int) (($targetPayload["server"]["id"] ?? null) ?: 0),
        ]));
    }
}
