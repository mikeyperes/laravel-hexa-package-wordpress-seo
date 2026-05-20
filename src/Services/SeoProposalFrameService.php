<?php

namespace hexa_package_wordpress_seo\Services;

class SeoProposalFrameService
{
    public function buildRows(array $current, array $proposed): array
    {
        $rows = [];

        foreach ((array) config("wordpress-seo.supported_features", ["seo_title", "seo_description"]) as $feature) {
            $before = $this->normalizeValue($current[$feature] ?? null);
            $after = $this->normalizeValue($proposed[$feature] ?? null);

            $rows[] = [
                "field" => $feature,
                "before" => $before,
                "after" => $after,
                "status" => $after === "" ? "missing" : ($before === $after ? "unchanged" : "pending"),
                "auto_fill" => $before === "" && $after !== "",
                "meta" => [
                    "before_empty" => $before === "",
                    "after_empty" => $after === "",
                ],
            ];
        }

        return $rows;
    }

    protected function normalizeValue(mixed $value): string
    {
        return trim((string) ($value ?? ""));
    }
}
