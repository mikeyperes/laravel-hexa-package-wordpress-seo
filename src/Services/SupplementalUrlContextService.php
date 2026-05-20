<?php

namespace hexa_package_wordpress_seo\Services;

use hexa_package_content_extractor\Import\Services\ExternalUrlImportService;

class SupplementalUrlContextService
{
    public function __construct(protected ExternalUrlImportService $extractor)
    {
    }

    public function detectUrls(string $text, ?int $limit = null): array
    {
        preg_match_all("/https?:\\/\\/[^\\s<>\"]+/i", $text, $matches);
        $urls = array_values(array_unique(array_map(static fn ($url) => rtrim((string) $url, ".,);]"), $matches[0] ?? [])));
        $cap = $limit ?? (int) config("wordpress-seo.url_context.max_urls", 5);

        return array_slice($urls, 0, max(0, $cap));
    }

    public function extractMany(array $urls): array
    {
        $results = [];

        foreach ($urls as $url) {
            $url = trim((string) $url);
            if ($url === "") {
                continue;
            }

            try {
                $payload = $this->extractor->import($url, false);
                $results[] = [
                    "url" => $url,
                    "success" => true,
                    "payload" => $payload,
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    "url" => $url,
                    "success" => false,
                    "error" => $e->getMessage(),
                ];
            }
        }

        return $results;
    }
}
