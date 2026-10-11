<?php

namespace hexa_package_wordpress_seo\Services;

/**
 * Plain-language H1, heading-order and URL-slug checks for one page from the
 * Rank Math page inventory. Heading checks use the served HTML, so they are
 * null when the page's served HTML was not fetched.
 */
final class PageStructureCheck
{
    /**
     * @param array<string, mixed> $page
     * @param array<int, string> $siteSlugs every slug on the site, to spot WordPress's duplicate "-2" suffix
     * @return array{h1: ?string, heading_order: ?string, slug: ?string}
     */
    public function check(array $page, array $siteSlugs = []): array
    {
        $headings = is_array($page['served_headings'] ?? null) ? $page['served_headings'] : null;

        return [
            'h1' => $headings === null ? null : $this->h1((int) ($headings['h1_count'] ?? 0), (array) ($headings['h1'] ?? [])),
            'heading_order' => $headings === null ? null : $this->headingOrder((array) ($headings['levels'] ?? [])),
            'slug' => ($page['status'] ?? '') === 'publish' ? $this->slug((string) ($page['slug'] ?? ''), $siteSlugs) : null,
        ];
    }

    /** @param array<int, string> $texts */
    private function h1(int $count, array $texts): string
    {
        if ($count === 1) {
            return 'ok';
        }
        if ($count === 0) {
            return 'no H1';
        }

        return $count . ' H1s: ' . implode(' | ', array_map(fn (string $text): string => '"' . $text . '"', $texts));
    }

    /** @param array<int, int> $levels heading levels in page order */
    private function headingOrder(array $levels): string
    {
        $skips = [];
        $previous = 1;
        foreach ($levels as $level) {
            $level = (int) $level;
            if ($level > $previous + 1) {
                $skips[] = 'h' . $previous . '→h' . $level;
            }
            $previous = $level;
        }
        $skips = array_values(array_unique($skips));

        return $skips === [] ? 'ok' : 'skips ' . implode(', ', array_slice($skips, 0, 5));
    }

    /**
     * Real slug defects only. Length is left to the pre-publish Rank Math
     * estimate: changing a live address breaks every link to it.
     *
     * @param array<int, string> $siteSlugs
     */
    private function slug(string $slug, array $siteSlugs): string
    {
        $issues = [];
        if (preg_match('/^(.+)-([2-9])$/', $slug, $match) && in_array($match[1], $siteSlugs, true)) {
            $issues[] = 'ends in -' . $match[2] . ' because "' . $match[1] . '" already exists';
        }
        if (str_contains($slug, '%')) {
            $issues[] = 'has encoded characters';
        }
        if (str_contains($slug, '_')) {
            $issues[] = 'uses underscores instead of hyphens';
        }
        if ($slug !== mb_strtolower($slug)) {
            $issues[] = 'has capital letters';
        }

        return $issues === [] ? 'ok' : implode('; ', $issues);
    }
}
