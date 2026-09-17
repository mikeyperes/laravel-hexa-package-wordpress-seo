<?php

namespace hexa_package_wordpress_seo\Services\Indexability;

use DOMDocument;
use DOMXPath;
use Throwable;

final class SitemapManifestBuilder
{
    public function __construct(private readonly PublicUrlInspector $inspector)
    {
    }

    public function build(array $seedUrls, array $options = []): array
    {
        $seedUrls = array_values(array_unique(array_filter(array_map('strval', $seedUrls), fn (string $url): bool => $this->isHttpUrl($url))));
        $maxDocuments = max(1, (int) ($options['max_sitemap_documents'] ?? 250));
        $maxDepth = max(0, (int) ($options['max_sitemap_depth'] ?? 8));
        $maxUrls = max(1, (int) ($options['max_sitemap_urls'] ?? 50000));
        $expectedHost = strtolower((string) ($options['expected_host'] ?? ''));
        $allowCrossHost = (bool) ($options['allow_cross_host_sitemaps'] ?? false);
        $queue = [];
        $queued = [];
        $documents = [];
        $urls = [];
        $urlSources = [];
        $errors = [];
        $truncated = false;

        foreach ($seedUrls as $seedUrl) {
            $normalized = $this->normalizeUrl($seedUrl);
            $queue[] = ["url" => $seedUrl, "depth" => 0, "discovered_from" => null];
            $queued[$normalized] = true;
        }

        while ($queue !== []) {
            if (count($documents) >= $maxDocuments) {
                $truncated = true;
                $errors[] = [
                    "code" => 'sitemap_document_limit',
                    "message" => "The sitemap document limit of {$maxDocuments} was reached.",
                ];
                break;
            }

            $item = array_shift($queue);
            $url = (string) ($item['url'] ?? '');
            $depth = (int) ($item['depth'] ?? 0);
            $response = $this->inspector->inspect($url, array_merge($options, [
                "include_body" => true,
                "max_body_bytes" => max(1024, (int) ($options['max_sitemap_body_bytes'] ?? 25 * 1024 * 1024)),
                "headers" => ["Accept" => 'application/xml,text/xml,application/gzip,text/plain;q=0.9,*/*;q=0.5'],
            ]));
            $body = (string) ($response['_body'] ?? '');
            unset($response['_body']);

            $document = [
                "url" => $url,
                "final_url" => (string) ($response['final_url'] ?? ''),
                "depth" => $depth,
                "discovered_from" => $item['discovered_from'] ?? null,
                "status_code" => $response['status_code'] ?? null,
                "content_type" => (string) ($response['content_type'] ?? ''),
                "sha256" => (string) ($response['body_sha256'] ?? ''),
                "type" => 'unknown',
                "child_sitemap_count" => 0,
                "url_count" => 0,
                "complete" => false,
                "error" => $response['error'] ?? null,
                "fetched_at" => (string) ($response['fetched_at'] ?? now()->toIso8601String()),
            ];

            if (!($response['success'] ?? false)) {
                $document['error'] = (string) ($response['error'] ?? 'The sitemap request failed.');
                $documents[] = $document;
                $errors[] = ["code" => 'sitemap_fetch_failed', "url" => $url, "message" => $document['error']];
                continue;
            }

            if ((bool) ($response['body_truncated'] ?? false)) {
                $document['error'] = 'The sitemap response exceeded the configured body limit.';
                $documents[] = $document;
                $errors[] = ["code" => 'sitemap_body_limit', "url" => $url, "message" => $document['error']];
                continue;
            }

            if ((int) ($response['status_code'] ?? 0) !== 200) {
                $document['error'] = 'The sitemap did not return HTTP 200.';
                $documents[] = $document;
                $errors[] = ["code" => 'sitemap_http_status', "url" => $url, "message" => $document['error']];
                continue;
            }

            $parsed = $this->parseXml($body, $url);
            if (!($parsed['success'] ?? false)) {
                $document['error'] = (string) ($parsed['message'] ?? 'The sitemap XML could not be parsed.');
                $documents[] = $document;
                $errors[] = ["code" => 'sitemap_xml_invalid', "url" => $url, "message" => $document['error']];
                continue;
            }

            $document['type'] = (string) ($parsed['type'] ?? 'unknown');
            $document['child_sitemap_count'] = count((array) ($parsed['sitemaps'] ?? []));
            $document['url_count'] = count((array) ($parsed['urls'] ?? []));
            $document['complete'] = true;
            $documents[] = $document;

            foreach ((array) ($parsed['urls'] ?? []) as $pageUrl) {
                $pageUrl = trim((string) $pageUrl);
                if (!$this->isHttpUrl($pageUrl)) {
                    $errors[] = ["code" => 'sitemap_url_invalid', "url" => $pageUrl, "message" => 'A sitemap entry is not an HTTP URL.'];
                    continue;
                }

                $normalized = $this->normalizeUrl($pageUrl);
                if (!isset($urls[$normalized])) {
                    if (count($urls) >= $maxUrls) {
                        $truncated = true;
                        continue;
                    }
                    $urls[$normalized] = $pageUrl;
                    $urlSources[$normalized] = [$url];
                } elseif (!in_array($url, $urlSources[$normalized], true)) {
                    $urlSources[$normalized][] = $url;
                }
            }

            foreach ((array) ($parsed['sitemaps'] ?? []) as $childUrl) {
                $childUrl = trim((string) $childUrl);
                if (!$this->isHttpUrl($childUrl)) {
                    $errors[] = ["code" => 'nested_sitemap_url_invalid', "url" => $childUrl, "message" => 'A nested sitemap entry is not an HTTP URL.'];
                    continue;
                }

                $childHost = strtolower((string) parse_url($childUrl, PHP_URL_HOST));
                if (!$allowCrossHost && $expectedHost !== '' && $childHost !== $expectedHost) {
                    $errors[] = ["code" => 'cross_host_sitemap', "url" => $childUrl, "message" => 'A nested sitemap points to a different host.'];
                    continue;
                }

                if ($depth >= $maxDepth) {
                    $truncated = true;
                    $errors[] = ["code" => 'sitemap_depth_limit', "url" => $childUrl, "message" => "The sitemap depth limit of {$maxDepth} was reached."];
                    continue;
                }

                $normalized = $this->normalizeUrl($childUrl);
                if (!isset($queued[$normalized])) {
                    $queued[$normalized] = true;
                    $queue[] = ["url" => $childUrl, "depth" => $depth + 1, "discovered_from" => $url];
                }
            }
        }

        $hasUrlLimitError = false;
        foreach ($errors as $error) {
            if (($error['code'] ?? '') === 'sitemap_url_limit') {
                $hasUrlLimitError = true;
                break;
            }
        }
        if ($truncated && !$hasUrlLimitError) {
            if (count($urls) >= $maxUrls) {
                $errors[] = [
                    "code" => 'sitemap_url_limit',
                    "message" => "The sitemap URL limit of {$maxUrls} was reached.",
                ];
            }
        }

        ksort($urls);
        $frozenUrls = array_values($urls);
        $manifestRows = array_map(fn (string $pageUrl): array => [
            "url" => $pageUrl,
            "sitemaps" => array_values($urlSources[$this->normalizeUrl($pageUrl)] ?? []),
        ], $frozenUrls);

        return [
            "complete" => !$truncated && $errors === [] && $seedUrls !== [],
            "truncated" => $truncated,
            "seed_urls" => $seedUrls,
            "documents" => $documents,
            "document_count" => count($documents),
            "urls" => $frozenUrls,
            "url_count" => count($frozenUrls),
            "manifest_sha256" => hash('sha256', json_encode($manifestRows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            "manifest" => $manifestRows,
            "errors" => $errors,
            "frozen_at" => now()->toIso8601String(),
        ];
    }

    private function parseXml(string $body, string $url): array
    {
        if (str_starts_with($body, "\x1f\x8b")) {
            $decoded = function_exists('gzdecode') ? @gzdecode($body) : false;
            if (!is_string($decoded)) {
                return ["success" => false, "message" => 'The compressed sitemap could not be decoded.'];
            }
            $body = $decoded;
        }

        if (!class_exists(DOMDocument::class)) {
            return ["success" => false, "message" => 'The DOM XML extension is unavailable.'];
        }

        try {
            $previous = libxml_use_internal_errors(true);
            $document = new DOMDocument();
            $loaded = $document->loadXML($body, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_NOBLANKS);
            $xmlErrors = libxml_get_errors();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            if (!$loaded || !$document->documentElement) {
                $message = $xmlErrors !== [] ? trim((string) $xmlErrors[0]->message) : 'Invalid XML.';
                return ["success" => false, "message" => $message];
            }

            $type = strtolower((string) $document->documentElement->localName);
            $xpath = new DOMXPath($document);

            if ($type === 'sitemapindex') {
                return [
                    "success" => true,
                    "type" => $type,
                    "sitemaps" => $this->nodeValues($xpath, '/*[local-name()="sitemapindex"]/*[local-name()="sitemap"]/*[local-name()="loc"]'),
                    "urls" => [],
                ];
            }

            if ($type === 'urlset') {
                return [
                    "success" => true,
                    "type" => $type,
                    "sitemaps" => [],
                    "urls" => $this->nodeValues($xpath, '/*[local-name()="urlset"]/*[local-name()="url"]/*[local-name()="loc"]'),
                ];
            }

            return ["success" => false, "message" => "Unexpected sitemap root element: {$type}."];
        } catch (Throwable $error) {
            return ["success" => false, "message" => $error->getMessage()];
        }
    }

    private function nodeValues(DOMXPath $xpath, string $query): array
    {
        $values = [];
        $nodes = $xpath->query($query);
        if ($nodes === false) {
            return [];
        }

        foreach ($nodes as $node) {
            $value = html_entity_decode(trim((string) $node->textContent), ENT_QUOTES | ENT_XML1, 'UTF-8');
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }

    private function normalizeUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || empty($parts['host'])) {
            return trim($url);
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) $parts['host']);
        $path = (string) ($parts['path'] ?? '/');
        $path = $path === '/' ? '/' : rtrim($path, '/');
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        return $scheme . '://' . $host . $path . $query;
    }

    private function isHttpUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true)
            && (string) parse_url($url, PHP_URL_HOST) !== '';
    }
}
