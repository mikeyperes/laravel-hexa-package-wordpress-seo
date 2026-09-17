<?php

namespace hexa_package_wordpress_seo\Services\Indexability;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Throwable;

final class PublicUrlInspector
{
    public function __construct(private readonly HttpFactory $http)
    {
    }

    public function inspect(string $url, array $options = []): array
    {
        if (!$this->isHttpUrl($url)) {
            return $this->failure($url, 'Only public HTTP and HTTPS URLs can be inspected.');
        }

        try {
            $response = $this->http
                ->withHeaders($this->headers($options))
                ->connectTimeout($this->connectTimeout($options))
                ->timeout($this->timeout($options))
                ->withOptions($this->requestOptions($options))
                ->get($url);

            return $this->responsePayload($url, $response, $options);
        } catch (Throwable $error) {
            return $this->failure($url, $error->getMessage());
        }
    }

    public function inspectMany(array $urls, array $options = []): array
    {
        $urls = array_values(array_unique(array_filter(array_map('strval', $urls), fn (string $url): bool => $this->isHttpUrl($url))));
        $results = [];
        $concurrency = max(1, (int) ($options['concurrency'] ?? 8));

        foreach (array_chunk($urls, $concurrency) as $chunkIndex => $chunk) {
            $keys = [];

            try {
                $responses = $this->http->pool(function (Pool $pool) use ($chunk, $chunkIndex, $options, &$keys): array {
                    $requests = [];
                    foreach ($chunk as $index => $url) {
                        $key = 'url_' . $chunkIndex . '_' . $index;
                        $keys[$key] = $url;
                        $requests[] = $pool
                            ->as($key)
                            ->withHeaders($this->headers($options))
                            ->connectTimeout($this->connectTimeout($options))
                            ->timeout($this->timeout($options))
                            ->withOptions($this->requestOptions($options))
                            ->get($url);
                    }

                    return $requests;
                });

                foreach ($keys as $key => $url) {
                    $response = $responses[$key] ?? null;
                    $results[$url] = $response instanceof Response
                        ? $this->responsePayload($url, $response, $options)
                        : $this->failure($url, $response instanceof Throwable ? $response->getMessage() : 'No HTTP response was returned.');
                }
            } catch (Throwable $error) {
                foreach ($chunk as $url) {
                    $results[$url] = $this->failure($url, $error->getMessage());
                }
            }
        }

        return $results;
    }

    private function responsePayload(string $requestedUrl, Response $response, array $options): array
    {
        $body = $response->body();
        $bodyBytes = strlen($body);
        $maxBodyBytes = max(1024, (int) ($options['max_body_bytes'] ?? 10 * 1024 * 1024));
        $analysisBody = $bodyBytes > $maxBodyBytes ? substr($body, 0, $maxBodyBytes) : $body;
        $handlerStats = (array) $response->handlerStats();
        $redirectHistory = $this->splitHeader($response->header('X-Guzzle-Redirect-History'));
        $redirectStatuses = array_map('intval', $this->splitHeader($response->header('X-Guzzle-Redirect-Status-History')));
        $finalUrl = trim((string) ($handlerStats['url'] ?? ''));
        if ($finalUrl === '') {
            $finalUrl = (string) (end($redirectHistory) ?: $requestedUrl);
        }

        $contentType = strtolower((string) $response->header('Content-Type'));
        $looksHtml = str_contains($contentType, 'text/html')
            || preg_match('/^\s*(?:<!doctype\s+html|<html\b)/i', $analysisBody) === 1;
        $title = $looksHtml ? $this->extractTitle($analysisBody) : '';
        $metaRobots = $looksHtml ? $this->extractMetaRobots($analysisBody) : [];
        $canonical = $looksHtml ? $this->extractCanonical($analysisBody) : '';
        $links = $looksHtml ? $this->extractLinks($analysisBody, $finalUrl) : [];
        $text = $looksHtml ? $this->normalizeText($analysisBody) : '';
        $blocking = $this->blockingEvidence($response->status(), $response->headers(), $title, $analysisBody);

        $payload = [
            "success" => true,
            "requested_url" => $requestedUrl,
            "final_url" => $finalUrl,
            "status_code" => $response->status(),
            "redirected" => $redirectHistory !== [] || $this->normalizeUrl($requestedUrl) !== $this->normalizeUrl($finalUrl),
            "redirect_history" => $redirectHistory,
            "redirect_status_history" => $redirectStatuses,
            "https" => strtolower((string) parse_url($finalUrl, PHP_URL_SCHEME)) === 'https',
            "content_type" => $contentType,
            "content_length" => $bodyBytes,
            "body_truncated" => $bodyBytes > $maxBodyBytes,
            "body_sha256" => hash('sha256', $body),
            "body_text_sha256" => $text !== '' ? hash('sha256', $text) : '',
            "title" => $title,
            "x_robots_tag" => $this->headerValues($response->headers(), 'X-Robots-Tag'),
            "meta_robots" => $metaRobots,
            "canonical_url" => $canonical !== '' ? $this->absoluteUrl($canonical, $finalUrl) : '',
            "links" => $links,
            "blocked" => $blocking['blocked'],
            "block_reason" => $blocking['reason'],
            "headers" => array_filter([
                "server" => (string) $response->header('Server'),
                "www_authenticate" => (string) $response->header('WWW-Authenticate'),
                "cf_ray" => (string) $response->header('CF-Ray'),
                "cf_mitigated" => (string) $response->header('CF-Mitigated'),
            ], static fn (string $value): bool => $value !== ''),
            "fetched_at" => now()->toIso8601String(),
            "error" => null,
        ];

        if ((bool) ($options['include_body'] ?? false)) {
            $payload['_body'] = $analysisBody;
        }

        return $payload;
    }

    private function failure(string $url, string $message): array
    {
        return [
            "success" => false,
            "requested_url" => $url,
            "final_url" => '',
            "status_code" => null,
            "redirected" => false,
            "redirect_history" => [],
            "redirect_status_history" => [],
            "https" => false,
            "content_type" => '',
            "content_length" => 0,
            "body_truncated" => false,
            "body_sha256" => '',
            "body_text_sha256" => '',
            "title" => '',
            "x_robots_tag" => [],
            "meta_robots" => [],
            "canonical_url" => '',
            "links" => [],
            "blocked" => false,
            "block_reason" => '',
            "headers" => [],
            "fetched_at" => now()->toIso8601String(),
            "error" => $message,
        ];
    }

    private function headers(array $options): array
    {
        return array_merge([
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,text/plain;q=0.8,*/*;q=0.5',
            'Cache-Control' => 'no-cache',
            'User-Agent' => (string) ($options['user_agent'] ?? 'Mozilla/5.0 (compatible; HWS-IndexabilityScanner/1.0; +https://hexawebsystems.com)'),
        ], (array) ($options['headers'] ?? []));
    }

    private function requestOptions(array $options): array
    {
        return [
            'allow_redirects' => [
                'max' => max(0, (int) ($options['max_redirects'] ?? 8)),
                'strict' => true,
                'track_redirects' => true,
            ],
            'http_errors' => false,
            'verify' => true,
        ];
    }

    private function timeout(array $options): int
    {
        return max(1, (int) ($options['timeout'] ?? 12));
    }

    private function connectTimeout(array $options): int
    {
        return max(1, (int) ($options['connect_timeout'] ?? 5));
    }

    private function extractTitle(string $html): string
    {
        if (preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $html, $match) !== 1) {
            return '';
        }

        return $this->decodeText(strip_tags((string) $match[1]));
    }

    private function extractMetaRobots(string $html): array
    {
        $values = [];
        if (preg_match_all('/<meta\b[^>]*>/is', $html, $matches) !== false) {
            foreach ((array) ($matches[0] ?? []) as $tag) {
                $attributes = $this->attributes((string) $tag);
                $name = strtolower((string) ($attributes['name'] ?? ''));
                if (in_array($name, ['robots', 'googlebot', 'googlebot-news'], true)) {
                    $values[$name] = trim((string) ($attributes['content'] ?? ''));
                }
            }
        }

        return $values;
    }

    private function extractCanonical(string $html): string
    {
        if (preg_match_all('/<link\b[^>]*>/is', $html, $matches) === false) {
            return '';
        }

        foreach ((array) ($matches[0] ?? []) as $tag) {
            $attributes = $this->attributes((string) $tag);
            $relations = preg_split('/\s+/', strtolower((string) ($attributes['rel'] ?? ''))) ?: [];
            if (in_array('canonical', $relations, true)) {
                return trim((string) ($attributes['href'] ?? ''));
            }
        }

        return '';
    }

    private function extractLinks(string $html, string $baseUrl): array
    {
        if (preg_match_all('/<a\b[^>]*>/is', $html, $matches) === false) {
            return [];
        }

        $links = [];
        foreach ((array) ($matches[0] ?? []) as $tag) {
            $href = trim((string) ($this->attributes((string) $tag)['href'] ?? ''));
            if (
                $href === ''
                || str_starts_with($href, '#')
                || preg_match('/^(?:mailto|tel|javascript|data):/i', $href) === 1
            ) {
                continue;
            }

            $absolute = $this->absoluteUrl($href, $baseUrl);
            if ($this->isHttpUrl($absolute)) {
                $links[] = $absolute;
            }
        }

        return array_values(array_unique($links));
    }

    private function attributes(string $tag): array
    {
        $attributes = [];
        if (preg_match_all('/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*(?:(["\'])(.*?)\2|([^\s>]+))/s', $tag, $matches, PREG_SET_ORDER) === false) {
            return $attributes;
        }

        foreach ($matches as $match) {
            $attributes[strtolower((string) $match[1])] = $this->decodeText((string) (($match[3] ?? '') !== '' ? $match[3] : ($match[4] ?? '')));
        }

        return $attributes;
    }

    private function blockingEvidence(int $status, array $headers, string $title, string $body): array
    {
        $haystack = strtolower($title . "\n" . substr($body, 0, 20000));
        $cfMitigated = strtolower(implode(' ', $this->headerValues($headers, 'CF-Mitigated')));

        $patterns = [
            'cloudflare_challenge' => ['just a moment', 'attention required! | cloudflare', 'cf-chl-', 'challenge-platform'],
            'maintenance' => ['maintenance mode', 'briefly unavailable for scheduled maintenance', 'site is under maintenance', 'maintenance in progress'],
            'coming_soon' => ['coming soon mode', 'website is coming soon'],
            'password' => ['password protected', 'post-password-form', 'this content is password protected'],
            'waf' => ['access denied', 'request blocked', 'security check', 'web application firewall'],
        ];

        if ($cfMitigated !== '') {
            return ["blocked" => true, "reason" => 'cloudflare_challenge'];
        }

        foreach ($patterns as $reason => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    return ["blocked" => true, "reason" => $reason];
                }
            }
        }

        if (in_array($status, [401, 403, 407, 423, 429, 451, 503], true)) {
            return ["blocked" => true, "reason" => 'http_' . $status];
        }

        return ["blocked" => false, "reason" => ''];
    }

    private function normalizeText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return substr($text, 0, 500000);
    }

    private function decodeText(string $value): string
    {
        return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function headerValues(array $headers, string $name): array
    {
        foreach ($headers as $key => $values) {
            if (strcasecmp((string) $key, $name) === 0) {
                return array_values(array_filter(array_map('strval', (array) $values)));
            }
        }

        return [];
    }

    private function splitHeader(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    private function absoluteUrl(string $url, string $baseUrl): string
    {
        if ($this->isHttpUrl($url)) {
            return $url;
        }

        $scheme = (string) parse_url($baseUrl, PHP_URL_SCHEME);
        $host = (string) parse_url($baseUrl, PHP_URL_HOST);
        $port = parse_url($baseUrl, PHP_URL_PORT);
        if ($scheme === '' || $host === '') {
            return $url;
        }

        $authority = $scheme . '://' . $host . ($port ? ':' . $port : '');
        if (str_starts_with($url, '//')) {
            return $scheme . ':' . $url;
        }
        if (str_starts_with($url, '/')) {
            return $authority . $url;
        }

        $basePath = (string) parse_url($baseUrl, PHP_URL_PATH);
        $directory = rtrim(str_replace('\\', '/', dirname($basePath ?: '/')), '/');

        return $authority . ($directory !== '' ? $directory : '') . '/' . ltrim($url, '/');
    }

    private function normalizeUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || empty($parts['host'])) {
            return trim($url);
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) && !in_array([$scheme, (int) $parts['port']], [['http', 80], ['https', 443]], true)
            ? ':' . (int) $parts['port']
            : '';
        $path = (string) ($parts['path'] ?? '/');
        $path = $path === '/' ? '/' : rtrim($path, '/');
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        return $scheme . '://' . $host . $port . $path . $query;
    }

    private function isHttpUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true)
            && (string) parse_url($url, PHP_URL_HOST) !== '';
    }
}
