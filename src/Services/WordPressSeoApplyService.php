<?php

namespace hexa_package_wordpress_seo\Services;

use hexa_package_wordpress_seo\Models\SeoExecutionRun;
use hexa_package_wordpress_seo\Models\SeoProposal;

class WordPressSeoApplyService
{
    public function __construct(
        protected SeoProviderRegistry $providers,
        protected SeoScanStoreService $activity,
    ) {
    }

    public function apply(SeoProposal $proposal): array
    {
        $proposal->loadMissing("pageRecord.target.scan");
        $pageRecord = $proposal->pageRecord;
        $target = $pageRecord?->target;

        $run = SeoExecutionRun::create([
            "seo_scan_id" => $target?->seo_scan_id,
            "seo_page_record_id" => $pageRecord?->id,
            "seo_proposal_id" => $proposal->id,
            "action" => "apply",
            "request_payload" => (array) ($proposal->proposed_payload ?? []),
            "status" => "running",
            "started_at" => now(),
            "meta" => [
                "provider_key" => $proposal->provider_key,
            ],
        ]);

        if (!$pageRecord || !$target || !is_array($target->target_payload ?? null) || !$pageRecord->external_page_id) {
            $message = "Proposal is missing target or page context.";
            return $this->fail($proposal, $run, $message);
        }

        $providerKey = $proposal->provider_key ?: ($target->provider_key ?: ($target->scan->provider_key ?? "rankmath"));
        $provider = $this->providers->get($providerKey);
        $result = $provider->writePage((array) $target->target_payload, (int) $pageRecord->external_page_id, (array) ($proposal->proposed_payload ?? []));

        $run->result_payload = $result;
        $run->completed_at = now();

        if (!($result["success"] ?? false)) {
            $run->status = "failed";
            $run->save();
            $message = (string) ($result["message"] ?? "SEO writeback failed.");
            return $this->fail($proposal, $run, $message, $result);
        }

        $proposal->status = "applied";
        $proposal->applied_at = now();
        $proposal->save();

        $pageRecord->current_payload = array_merge((array) ($pageRecord->current_payload ?? []), (array) ($result["page"] ?? []), (array) ($proposal->proposed_payload ?? []));
        $pageRecord->review_state = "applied";
        $pageRecord->save();

        $run->status = "completed";
        $run->save();

        $this->activity->recordActivity([
            "seo_scan_id" => $target->seo_scan_id,
            "seo_scan_target_id" => $target->id,
            "seo_page_record_id" => $pageRecord->id,
            "seo_execution_run_id" => $run->id,
            "event" => "proposal.applied",
            "message" => (string) ($result["message"] ?? "SEO proposal applied."),
            "context" => [
                "provider_key" => $providerKey,
                "page_id" => $pageRecord->external_page_id,
                "permalink" => $result["page"]["url"] ?? $pageRecord->permalink,
            ],
        ]);

        return [
            "success" => true,
            "message" => (string) ($result["message"] ?? "SEO proposal applied."),
            "execution_run_id" => $run->id,
            "page" => $result["page"] ?? null,
        ];
    }

    protected function fail(SeoProposal $proposal, SeoExecutionRun $run, string $message, array $result = []): array
    {
        $proposal->status = "failed";
        $proposal->save();

        if ($proposal->pageRecord) {
            $proposal->pageRecord->review_state = "failed";
            $proposal->pageRecord->save();
        }

        $this->activity->recordActivity([
            "seo_scan_id" => $proposal->pageRecord?->target?->seo_scan_id,
            "seo_scan_target_id" => $proposal->pageRecord?->target?->id,
            "seo_page_record_id" => $proposal->pageRecord?->id,
            "seo_execution_run_id" => $run->id,
            "level" => "error",
            "event" => "proposal.apply.failed",
            "message" => $message,
            "context" => $result,
        ]);

        return [
            "success" => false,
            "message" => $message,
            "execution_run_id" => $run->id,
            "result" => $result,
        ];
    }
}
