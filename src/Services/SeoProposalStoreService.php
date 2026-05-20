<?php

namespace hexa_package_wordpress_seo\Services;

use hexa_package_wordpress_seo\Models\SeoPageRecord;
use hexa_package_wordpress_seo\Models\SeoProposal;

class SeoProposalStoreService
{
    public function __construct(protected SeoProposalFrameService $frames)
    {
    }

    public function store(SeoPageRecord $pageRecord, array $proposedPayload, array $options = []): SeoProposal
    {
        $before = [
            "seo_title" => (string) ($pageRecord->current_payload["seo_title"] ?? ""),
            "seo_description" => (string) ($pageRecord->current_payload["seo_description"] ?? ""),
        ];

        $rows = $this->frames->buildRows($before, $proposedPayload);

        return SeoProposal::create([
            "seo_page_record_id" => $pageRecord->id,
            "provider_key" => (string) ($options["provider_key"] ?? $pageRecord->target->provider_key ?? "rankmath"),
            "feature_set" => array_values(array_unique(array_keys(array_filter($proposedPayload, static fn ($value) => trim((string) $value) !== "")))),
            "before_payload" => $before,
            "proposed_payload" => $proposedPayload,
            "review_payload" => ["rows" => $rows],
            "status" => (string) ($options["status"] ?? $this->deriveStatus($rows)),
            "agent_driver" => (string) ($options["agent_driver"] ?? ""),
            "agent_model" => (string) ($options["agent_model"] ?? ""),
            "extracted_urls" => (array) ($options["extracted_urls"] ?? []),
            "proposed_at" => $options["proposed_at"] ?? now(),
            "meta" => (array) ($options["meta"] ?? []),
        ]);
    }

    protected function deriveStatus(array $rows): string
    {
        $hasPending = false;
        $hasAutoFill = false;

        foreach ($rows as $row) {
            if (($row["status"] ?? null) === "pending") {
                $hasPending = true;
            }

            if ((bool) ($row["auto_fill"] ?? false)) {
                $hasAutoFill = true;
            }
        }

        if ($hasPending || $hasAutoFill) {
            return "proposed";
        }

        foreach ($rows as $row) {
            if (($row["status"] ?? null) === "missing") {
                return "missing";
            }
        }

        return "unchanged";
    }
}
