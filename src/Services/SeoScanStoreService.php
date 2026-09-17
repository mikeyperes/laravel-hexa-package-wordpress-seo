<?php

namespace hexa_package_wordpress_seo\Services;

use hexa_package_wordpress_seo\Models\SeoActivityLog;
use hexa_package_wordpress_seo\Models\SeoIndexabilityUrlRecord;
use hexa_package_wordpress_seo\Models\SeoPageRecord;
use hexa_package_wordpress_seo\Models\SeoScan;
use hexa_package_wordpress_seo\Models\SeoScanTarget;
use Illuminate\Support\Facades\Schema;

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
        $indexability = is_array($siteResult["indexability"] ?? null) ? (array) $siteResult["indexability"] : [];
        $meta = (array) ($siteResult["meta"] ?? []);
        if ($indexability !== []) {
            $meta["indexability"] = $this->indexabilitySummary($indexability);
        }
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
                "indexability_state" => (string) ($indexability["state"] ?? ""),
                "technical_indexable" => $indexability["technical_indexable"] ?? null,
                "sitemap_url_count" => (int) ($indexability["proof"]["sitemap_url_count"] ?? 0),
                "indexability_issue_count" => count((array) ($indexability["issues"] ?? [])),
            ],
            "meta" => $meta,
            "started_at" => now(),
            "completed_at" => now(),
        ]);

        $this->syncPageRecords($target, (array) ($siteResult["pages"] ?? []));
        $this->syncIndexabilityUrlRecords($target, (array) ($indexability["url_audits"] ?? []));

        $this->recordActivity([
            "seo_scan_id" => $scan->id,
            "seo_scan_target_id" => $target->id,
            "event" => "scan.site.stored",
            "message" => (string) ($siteResult["message"] ?? "Install scan result stored."),
            "context" => [
                "site_name" => $target->site_name,
                "site_url" => $target->site_url,
                "page_count" => count((array) ($siteResult["pages"] ?? [])),
                "indexability_state" => (string) ($indexability["state"] ?? ""),
                "sitemap_url_count" => (int) ($indexability["proof"]["sitemap_url_count"] ?? 0),
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
                "content_html" => (string) ($page["content_html"] ?? ""),
                "outbound_internal_links" => array_values((array) ($page["outbound_internal_links"] ?? [])),
                "seo_title" => (string) ($page["seo_title"] ?? ""),
                "seo_description" => (string) ($page["seo_description"] ?? ""),
                "effective_seo_title" => (string) ($page["effective_seo_title"] ?? ""),
                "effective_seo_description" => (string) ($page["effective_seo_description"] ?? ""),
                "seo_title_source" => (string) ($page["seo_title_source"] ?? ""),
                "seo_description_source" => (string) ($page["seo_description_source"] ?? ""),
                "effective_seo_source" => (string) ($page["effective_seo_source"] ?? ""),
                "seo_score" => $page["seo_score"] ?? null,
                "seo_score_raw" => (string) ($page["seo_score_raw"] ?? ""),
                "seo_score_source" => (string) ($page["seo_score_source"] ?? ""),
                "focus_keyword" => (string) ($page["focus_keyword"] ?? $page["rank_math_focus_keyword"] ?? ""),
                "rank_math_focus_keyword" => (string) ($page["rank_math_focus_keyword"] ?? $page["focus_keyword"] ?? ""),
                "canonical_url" => (string) ($page["canonical_url"] ?? ""),
                "robots" => (string) ($page["robots"] ?? ""),
                "featured_image" => (string) ($page["featured_image"] ?? $page["featured_image_url"] ?? ""),
                "featured_image_url" => (string) ($page["featured_image_url"] ?? $page["featured_image"] ?? ""),
                "featured_image_alt" => (string) ($page["featured_image_alt"] ?? ""),
                "featured_image_title" => (string) ($page["featured_image_title"] ?? ""),
                "featured_image_caption" => (string) ($page["featured_image_caption"] ?? ""),
                "featured_image_description" => (string) ($page["featured_image_description"] ?? ""),
                "featured_image_file_name" => (string) ($page["featured_image_file_name"] ?? ""),
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

    public function syncIndexabilityUrlRecords(SeoScanTarget $target, array $audits): array
    {
        if (!Schema::hasTable('wordpress_seo_indexability_url_records')) {
            return [];
        }

        $records = [];
        foreach ($audits as $audit) {
            if (!is_array($audit)) {
                continue;
            }

            $url = trim((string) ($audit["url"] ?? ""));
            if ($url === "") {
                continue;
            }

            $payload = $audit;
            unset($payload["links"]);
            $record = SeoIndexabilityUrlRecord::updateOrCreate(
                [
                    "seo_scan_target_id" => $target->id,
                    "url_hash" => hash('sha256', $url),
                ],
                [
                    "url" => $url,
                    "final_url" => (string) ($audit["final_url"] ?? ""),
                    "status_code" => isset($audit["status_code"]) ? (int) $audit["status_code"] : null,
                    "from_sitemap" => (bool) ($audit["source"]["sitemap"] ?? false),
                    "important" => (bool) ($audit["source"]["important"] ?? false),
                    "robots_allowed" => isset($audit["robots_allowed"]) ? (bool) $audit["robots_allowed"] : null,
                    "canonical_self" => array_key_exists("canonical_self", $audit) && $audit["canonical_self"] !== null
                        ? (bool) $audit["canonical_self"]
                        : null,
                    "soft_404" => (bool) ($audit["soft_404"] ?? false),
                    "access_blocked" => (bool) ($audit["blocked"] ?? false),
                    "indexable" => (bool) ($audit["indexable"] ?? false),
                    "canonical_url" => (string) ($audit["canonical_url"] ?? ""),
                    "response_sha256" => (string) ($audit["response_sha256"] ?? ""),
                    "reasons" => array_values((array) ($audit["reasons"] ?? [])),
                    "payload" => $payload,
                    "fetched_at" => $audit["fetched_at"] ?? null,
                ]
            );

            $records[] = $record;
        }

        return $records;
    }

    protected function indexabilitySummary(array $indexability): array
    {
        $summary = $indexability;
        unset($summary["url_audits"]);

        if (isset($summary["sitemaps"]["urls"])) {
            unset($summary["sitemaps"]["urls"]);
        }
        if (isset($summary["sitemaps"]["manifest"])) {
            unset($summary["sitemaps"]["manifest"]);
        }

        return $summary;
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
