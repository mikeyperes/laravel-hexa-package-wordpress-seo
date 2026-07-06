<?php

namespace hexa_package_wordpress_seo\Services;

class WordPressSeoScorePreviewService
{
    public function analyze(array $page, array $draft = []): array
    {
        $titleValue = $this->resolveMetricValue($page, $draft, 'seo_title', 'effective_seo_title');
        $descriptionValue = $this->resolveMetricValue($page, $draft, 'seo_description', 'effective_seo_description', 'description');

        $titleMetric = $this->metric(
            'seo_title',
            'SEO title',
            $titleValue['value'],
            50,
            60,
            65,
            $titleValue['source']
        );

        $descriptionMetric = $this->metric(
            'seo_description',
            'SEO description',
            $descriptionValue['value'],
            120,
            160,
            170,
            $descriptionValue['source']
        );

        $storedScore = $this->scoreValue($page['seo_score'] ?? $page['seo_score_raw'] ?? null);
        $previewScore = (int) round(($titleMetric['score'] + $descriptionMetric['score']) / 2);

        return [
            'success' => true,
            'source' => 'hexa_package_wordpress_seo:rank_math_field_preview',
            'score_source' => 'rank_math_seo_score',
            'old_score' => $storedScore,
            'preview_score' => $previewScore,
            'metrics' => [
                'seo_title' => $titleMetric,
                'seo_description' => $descriptionMetric,
            ],
            'message' => 'Previewed Rank Math title and description field health.',
            'checked_at' => now()->toIso8601String(),
        ];
    }

    private function resolveMetricValue(array $page, array $draft, string $field, string $effectiveField, ?string $fallbackField = null): array
    {
        $hasDraft = array_key_exists($field, $draft);
        $draftValue = $hasDraft ? (string) ($draft[$field] ?? '') : null;
        $storedValue = (string) ($page[$field] ?? '');
        $effectiveValue = (string) ($page[$effectiveField] ?? '');
        $fallbackValue = $fallbackField ? (string) ($page[$fallbackField] ?? '') : '';

        if ($hasDraft && trim((string) $draftValue) !== '') {
            return ['value' => (string) $draftValue, 'source' => 'draft'];
        }

        if (trim($storedValue) !== '') {
            return $hasDraft
                ? ['value' => (string) $draftValue, 'source' => 'draft']
                : ['value' => $storedValue, 'source' => $field];
        }

        if (trim($effectiveValue) !== '') {
            return ['value' => $effectiveValue, 'source' => $effectiveField];
        }

        if (trim($fallbackValue) !== '') {
            return ['value' => $fallbackValue, 'source' => (string) $fallbackField];
        }

        return ['value' => (string) ($draftValue ?? ''), 'source' => $hasDraft ? 'draft' : 'empty'];
    }

    private function metric(string $field, string $label, string $value, int $min, int $max, int $hardMax, string $valueSource = ''): array
    {
        $value = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $chars = mb_strlen($value);
        $words = $this->wordCount($value);
        $score = 100;
        $status = 'pass';
        $message = $label . ' is within the Rank Math preview range.';

        if ($chars === 0) {
            $score = 0;
            $status = 'fail';
            $message = $label . ' is empty.';
        } elseif ($chars < $min) {
            $score = max(20, (int) round(($chars / $min) * 85));
            $status = 'warn';
            $message = $label . ' is short; target ' . $min . '-' . $max . ' characters.';
        } elseif ($chars > $max) {
            $over = $chars - $max;
            $score = max(0, 100 - ($over * 4));
            $status = 'fail';
            $message = $label . ' is over ' . $max . ' characters.';
            if ($chars > $hardMax) {
                $message .= ' It is beyond the hard warning point of ' . $hardMax . ' characters.';
            }
        }

        return [
            'field' => $field,
            'label' => $label,
            'value' => $value,
            'value_source' => $valueSource,
            'chars' => $chars,
            'words' => $words,
            'min' => $min,
            'max' => $max,
            'hard_max' => $hardMax,
            'score' => $score,
            'status' => $status,
            'message' => $message,
        ];
    }

    private function wordCount(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        preg_match_all('/\S+/u', $value, $matches);
        return count($matches[0] ?? []);
    }

    private function scoreValue(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return max(0, min(100, (int) round((float) $value)));
        }

        if (preg_match('/\d+/', (string) $value, $match)) {
            return max(0, min(100, (int) $match[0]));
        }

        return null;
    }
}
