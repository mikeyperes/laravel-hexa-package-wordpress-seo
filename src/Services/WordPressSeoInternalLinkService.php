<?php

namespace hexa_package_wordpress_seo\Services;

class WordPressSeoInternalLinkService
{
    public function analyze(array $page, array $sitePages, array $options = []): array
    {
        $contentText = $this->normalizeWhitespace((string) ($page['content_text'] ?? $page['excerpt'] ?? ''));
        $contentHtml = (string) ($page['content_html'] ?? '');
        $existingLinks = $this->existingInternalLinks($page, $contentHtml, (string) ($options['site_url'] ?? $page['url'] ?? ''));
        $existingByUrl = [];
        foreach ($existingLinks as $link) {
            $existingByUrl[$this->normalizeUrl((string) ($link['url'] ?? ''))] = true;
        }

        $max = max(1, (int) ($options['max_suggestions'] ?? config('wordpress-seo.internal_links.max_suggestions', 8)));
        $currentUrl = $this->normalizeUrl((string) ($page['url'] ?? ''));
        $suggestions = [];
        $seen = [];

        foreach ($sitePages as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $targetUrl = $this->normalizeUrl((string) ($candidate['url'] ?? ''));
            $targetTitle = $this->cleanTitle((string) ($candidate['title'] ?? ''));
            if ($targetUrl === '' || $targetTitle === '' || $targetUrl === $currentUrl) {
                continue;
            }

            $key = $targetUrl . '|' . strtolower($targetTitle);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $position = $this->findPhrase($contentText, $targetTitle);
            $alreadyLinked = isset($existingByUrl[$targetUrl]);
            if ($position === null && !$alreadyLinked) {
                $related = $this->relatedScore($contentText, $targetTitle);
                if ($related < 2) {
                    continue;
                }
            }

            $snippet = $position !== null
                ? $this->snippet($contentText, $position['offset'], $position['length'])
                : $this->fallbackSnippet($contentText);

            $suggestions[] = [
                'target_id' => (int) ($candidate['id'] ?? 0),
                'target_title' => $targetTitle,
                'target_url' => (string) ($candidate['url'] ?? ''),
                'anchor_text' => $position['match'] ?? $targetTitle,
                'already_linked' => $alreadyLinked,
                'reason' => $alreadyLinked ? 'Already linked from this page.' : 'Relevant page title or entity text appears in this page content.',
                'snippet' => $snippet['text'],
                'snippet_html' => $snippet['html'],
            ];

            if (count($suggestions) >= $max) {
                break;
            }
        }

        return [
            'success' => true,
            'message' => count($suggestions) . ' internal link suggestion(s) found.',
            'score' => $page['seo_score'] ?? null,
            'score_source' => (string) ($page['seo_score_source'] ?? 'rank_math_seo_score'),
            'current_links' => $existingLinks,
            'suggestions' => $suggestions,
            'pages_considered' => count(array_filter($sitePages, 'is_array')),
            'content_text' => $contentText,
        ];
    }

    protected function existingInternalLinks(array $page, string $contentHtml, string $siteUrl): array
    {
        $links = array_values(array_filter((array) ($page['outbound_internal_links'] ?? []), 'is_array'));
        if ($links !== []) {
            return $links;
        }

        if ($contentHtml === '') {
            return [];
        }

        $siteHost = parse_url($siteUrl, PHP_URL_HOST);
        $found = [];
        if (preg_match_all('/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $contentHtml, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $url = (string) ($match[1] ?? '');
                $host = parse_url($url, PHP_URL_HOST);
                if ($url !== '' && ($host === null || $host === false || strtolower((string) $host) === strtolower((string) $siteHost))) {
                    $found[] = [
                        'url' => $url,
                        'text' => $this->normalizeWhitespace(strip_tags((string) ($match[2] ?? ''))),
                    ];
                }
            }
        }

        return $found;
    }

    protected function findPhrase(string $content, string $phrase): ?array
    {
        $phrase = $this->normalizeWhitespace($phrase);
        if (strlen($phrase) < 4 || $content === '') {
            return null;
        }

        $offset = stripos($content, $phrase);
        if ($offset === false) {
            return null;
        }

        return [
            'offset' => (int) $offset,
            'length' => strlen($phrase),
            'match' => substr($content, (int) $offset, strlen($phrase)),
        ];
    }

    protected function relatedScore(string $content, string $title): int
    {
        $tokens = array_unique(array_filter(preg_split('/[^a-z0-9]+/i', strtolower($title)) ?: [], static fn ($token) => strlen($token) >= 5));
        $score = 0;
        $haystack = strtolower($content);
        foreach ($tokens as $token) {
            if (str_contains($haystack, $token)) {
                $score++;
            }
        }

        return $score;
    }

    protected function snippet(string $content, int $offset, int $length): array
    {
        $start = max(0, $offset - 110);
        $end = min(strlen($content), $offset + $length + 110);
        $before = substr($content, $start, $offset - $start);
        $match = substr($content, $offset, $length);
        $after = substr($content, $offset + $length, $end - ($offset + $length));
        $prefix = $start > 0 ? '...' : '';
        $suffix = $end < strlen($content) ? '...' : '';

        return [
            'text' => $prefix . $before . $match . $after . $suffix,
            'html' => htmlspecialchars($prefix . $before, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '<mark>'
                . htmlspecialchars($match, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</mark>'
                . htmlspecialchars($after . $suffix, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        ];
    }

    protected function fallbackSnippet(string $content): array
    {
        $text = mb_substr($content, 0, 220);
        if (mb_strlen($content) > 220) {
            $text .= '...';
        }

        return [
            'text' => $text,
            'html' => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        ];
    }

    protected function cleanTitle(string $title): string
    {
        $title = html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = preg_replace('/\s+[-|]\s+.*$/', '', $title) ?: $title;
        return $this->normalizeWhitespace($title);
    }

    protected function normalizeWhitespace(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value) ?: '');
    }

    protected function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $url = preg_replace('/#.*$/', '', $url) ?: $url;
        return rtrim($url, '/');
    }
}
