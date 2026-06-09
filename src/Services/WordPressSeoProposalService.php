<?php

namespace hexa_package_wordpress_seo\Services;

class WordPressSeoProposalService
{
    public function supportedFields(): array
    {
        $fields = (array) config('wordpress-seo.proposals.fields', [
            'title',
            'slug',
            'seo_title',
            'seo_description',
            'image_url',
            'featured_image_alt',
            'featured_image_title',
            'featured_image_description',
            'featured_image_caption',
            'featured_image_file_name',
        ]);

        return array_values(array_is_list($fields) ? $fields : array_keys($fields));
    }

    public function fieldLabel(string $field): string
    {
        return (string) (config('wordpress-seo.proposals.labels.' . $field) ?: match ($field) {
            'title' => 'WordPress title',
            'slug' => 'Slug',
            'seo_title' => 'Rank Math SEO title',
            'seo_description' => 'Rank Math SEO description',
            'image_url' => 'Featured image URL',
            'featured_image_alt' => 'Image alt text',
            'featured_image_title' => 'Image title',
            'featured_image_description' => 'Image description',
            'featured_image_caption' => 'Image caption',
            'featured_image_file_name' => 'Image file name',
            default => str_replace('_', ' ', $field),
        });
    }

    public function proposeField(array $input): array
    {
        if (!class_exists(\hexa_core\AI\Services\AiChatGateway::class) || !class_exists(\hexa_core\AI\Services\AiModelCatalog::class)) {
            return ['success' => false, 'message' => 'Hexa AI model catalog is unavailable.'];
        }

        $field = trim((string) ($input['field'] ?? 'seo_title'));
        if (!in_array($field, $this->supportedFields(), true)) {
            return ['success' => false, 'message' => 'Unsupported SEO proposal field: ' . $field];
        }

        $catalog = app(\hexa_core\AI\Services\AiModelCatalog::class);
        $model = $this->resolveModel(trim((string) ($input['ai_agent'] ?? $input['model'] ?? '')), $catalog);
        $payload = $this->buildPayload($input, $field);

        try {
            $result = app(\hexa_core\AI\Services\AiChatGateway::class)->chat(
                $this->systemPrompt($field),
                (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $model,
                0.2,
                1400
            );
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'field' => $field];
        }

        if (!($result['success'] ?? false)) {
            return [
                'success' => false,
                'message' => (string) ($result['message'] ?? 'AI proposal failed.'),
                'field' => $field,
                'model' => $model,
            ];
        }

        $parsed = $this->parseResponse($result);
        if (!is_array($parsed)) {
            return ['success' => false, 'message' => 'AI returned an unreadable payload.', 'field' => $field, 'model' => $model];
        }

        $value = (string) ($parsed['value'] ?? $parsed[$field] ?? '');
        $modelValue = $value;
        if ($field === 'slug') {
            $value = $this->normalizeSlug($value);
        }
        if ($field === 'featured_image_file_name') {
            $value = $this->normalizeFileName($value);
        }
        if ($this->isRankMathTextField($field)) {
            $value = $this->fitRankMathText($field, $this->normalizeRankMathText($value));
        }

        $rationale = trim((string) ($parsed['rationale'] ?? $parsed['why'] ?? ''));
        if ($this->isRankMathTextField($field)) {
            $constraints = $this->rankMathConstraintsForField($field);
            $limitLine = 'Generated within Rank Math guideline: ' . $constraints['target_min'] . '-' . $constraints['target_max'] . ' characters; must not exceed ' . $constraints['absolute_max'] . '.';
            if ($modelValue !== $value) {
                $limitLine .= ' The package normalized the model output to fit that limit.';
            }
            $rationale = trim($rationale . ($rationale !== '' ? ' ' : '') . $limitLine);
        }

        return [
            'success' => true,
            'field' => $field,
            'label' => $this->fieldLabel($field),
            'value' => $value,
            'rationale' => $rationale,
            'model' => data_get($result, 'data.model', $model),
            'rules' => $this->rulesForField($field),
            'rank_math_constraints' => $this->rankMathConstraintsForField($field),
            'metric' => $this->metricForProposal($field, $input, $value),
        ];
    }

    public function proposeFields(array $input, array $fields = [], string $model = ''): array
    {
        if (!class_exists(\hexa_core\AI\Services\AiChatGateway::class) || !class_exists(\hexa_core\AI\Services\AiModelCatalog::class)) {
            return ['success' => false, 'message' => 'Hexa AI model catalog is unavailable.', 'fields' => [], 'failures' => []];
        }

        $supported = $this->supportedFields();
        $fields = $fields !== [] ? array_values(array_unique(array_map('strval', $fields))) : $supported;
        $fields = array_values(array_filter($fields, fn ($field) => in_array($field, $supported, true)));

        if ($fields === []) {
            return ['success' => false, 'message' => 'No supported SEO proposal fields were requested.', 'fields' => [], 'failures' => []];
        }

        $catalog = app(\hexa_core\AI\Services\AiModelCatalog::class);
        $resolvedModel = $this->resolveModel($model ?: trim((string) ($input['ai_agent'] ?? $input['model'] ?? '')), $catalog);
        $payload = $this->buildBatchPayload($input, $fields);

        try {
            $chat = app(\hexa_core\AI\Services\AiChatGateway::class)->chat(
                $this->batchSystemPrompt($fields),
                (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $resolvedModel,
                0.2,
                3600
            );
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'fields' => [], 'failures' => array_fill_keys($fields, $e->getMessage())];
        }

        if (!($chat['success'] ?? false)) {
            $message = (string) ($chat['message'] ?? 'AI proposal failed.');
            return ['success' => false, 'message' => $message, 'fields' => [], 'failures' => array_fill_keys($fields, $message), 'model' => $resolvedModel];
        }

        $parsed = $this->parseResponse($chat);
        if (!is_array($parsed)) {
            return ['success' => false, 'message' => 'AI returned an unreadable batch payload.', 'fields' => [], 'failures' => array_fill_keys($fields, 'Unreadable AI payload.'), 'model' => $resolvedModel];
        }

        $proposals = $this->extractBatchProposals($parsed);
        $results = [];
        $failures = [];

        foreach ($fields as $field) {
            $proposal = $proposals[$field] ?? null;
            if ($proposal !== null) {
                $results[$field] = $this->formatProposalResult($field, $input, $proposal, $chat, $resolvedModel);
            } else {
                $failures[$field] = 'AI batch response did not include this field.';
            }
        }

        return [
            'success' => $results !== [],
            'message' => $results !== [] ? 'SEO proposals generated in one batch request.' : 'No SEO proposals were generated.',
            'fields' => $results,
            'failures' => $failures,
            'model' => data_get($chat, 'data.model', $resolvedModel),
            'batch' => true,
        ];
    }

    public function buildPayload(array $input, string $field): array
    {
        return [
            'field' => $field,
            'field_label' => $this->fieldLabel($field),
            'current_value' => $this->currentValueForField($input, $field),
            'rules' => $this->rulesForField($field),
            'rank_math_constraints' => $this->rankMathConstraintsForField($field),
            'site_url' => (string) ($input['site_url'] ?? ''),
            'person_name' => (string) ($input['person_name'] ?? ''),
            'page' => (array) ($input['page'] ?? []),
            'entity_context' => (array) ($input['entity_context'] ?? []),
            'notion_context' => (array) ($input['notion_context'] ?? [
                'person' => $input['notion_data'] ?? [],
                'books' => $input['notion_books'] ?? [],
                'companies' => $input['notion_companies'] ?? [],
            ]),
        ];
    }

    public function buildBatchPayload(array $input, array $fields): array
    {
        return [
            'mode' => 'batch',
            'fields' => array_values(array_map(fn ($field) => $this->buildPayload($input, (string) $field), $fields)),
            'site_url' => (string) ($input['site_url'] ?? ''),
            'person_name' => (string) ($input['person_name'] ?? ''),
            'page' => (array) ($input['page'] ?? []),
            'entity_context' => (array) ($input['entity_context'] ?? []),
            'notion_context' => (array) ($input['notion_context'] ?? [
                'person' => $input['notion_data'] ?? [],
                'books' => $input['notion_books'] ?? [],
                'companies' => $input['notion_companies'] ?? [],
            ]),
        ];
    }

    public function rulesForField(string $field): array
    {
        $common = [
            'use_only_supplied_context' => true,
            'do_not_invent_facts' => true,
            'return_json_schema' => ['value' => 'string', 'rationale' => 'string'],
        ];

        $specific = match ($field) {
            'title' => ['target' => 'WordPress post title', 'guidance' => 'Readable page title. Include the person/entity and page purpose when useful.'],
            'slug' => ['target' => 'WordPress post slug', 'guidance' => 'Lowercase, hyphenated, concise, no domain, no slash.'],
            'seo_title' => ['target' => 'Rank Math title', 'guidance' => 'Target 50-60 characters. Do not exceed 60 characters. Include the entity and page intent using supplied context only.'],
            'seo_description' => ['target' => 'Rank Math description', 'guidance' => 'Target 120-160 characters. Do not exceed 160 characters. Accurate summary with useful search context using supplied context only.'],
            'image_url' => ['target' => 'featured image URL', 'guidance' => 'Return a direct image URL only when explicitly supplied; otherwise return an empty string.'],
            'featured_image_alt' => ['target' => 'image alt text', 'guidance' => 'Describe the actual image and entity. Do not keyword stuff.'],
            'featured_image_title' => ['target' => 'image title', 'guidance' => 'Short title case image title.'],
            'featured_image_description' => ['target' => 'image description', 'guidance' => 'One useful sentence describing the image.'],
            'featured_image_caption' => ['target' => 'image caption', 'guidance' => 'Short visible caption if appropriate; otherwise return empty.'],
            'featured_image_file_name' => ['target' => 'SEO image file name', 'guidance' => 'Lowercase hyphenated filename with extension when known.'],
            default => ['target' => $field, 'guidance' => 'Produce a concise production value.'],
        };

        $rules = array_merge($common, $specific);
        $constraints = $this->rankMathConstraintsForField($field);
        if ($constraints !== []) {
            $rules['rank_math_constraints'] = $constraints;
        }

        return $rules;
    }

    protected function systemPrompt(string $field): string
    {
        $label = $this->fieldLabel($field);
        $lines = [
            'You generate one production-ready WordPress SEO field.',
            'The SEO provider is Rank Math when the field is Rank Math metadata.',
            'The caller supplies the app/domain context; this service is generic and must not assume an application.',
            'Use only the supplied payload. Do not invent facts.',
            'Requested field: ' . $field . ' (' . $label . ').',
        ];

        if ($this->isRankMathTextField($field)) {
            $constraints = $this->rankMathConstraintsForField($field);
            $lines[] = 'Rank Math length rule: target ' . $constraints['target_min'] . '-' . $constraints['target_max'] . ' characters; never exceed ' . $constraints['absolute_max'] . ' characters.';
            $lines[] = 'Count visible characters only. Avoid filler, pipes, duplicated names, and unsupported claims.';
        }

        $lines[] = 'Return strict JSON only: {"value":"","rationale":""}.';
        $lines[] = 'If the current value is already best, return the same value and explain why.';

        return implode("\n", $lines);
    }

    protected function batchSystemPrompt(array $fields): string
    {
        $lines = [
            'You generate production-ready WordPress SEO field proposals in one batch.',
            'The SEO provider is Rank Math when fields are Rank Math metadata.',
            'The caller supplies the app/domain context; this service is generic and must not assume an application.',
            'Use only the supplied payload. Do not invent facts.',
            'Requested fields: ' . implode(', ', $fields) . '.',
            'For Rank Math title fields: target 50-60 visible characters and never exceed 60.',
            'For Rank Math description fields: target 120-160 visible characters and never exceed 160.',
            'Avoid filler, pipes, duplicated names, and unsupported claims.',
            'Return strict JSON only in this schema: {"fields":{"field_name":{"value":"","rationale":""}}}.',
            'If a current value is already best, return the same value and explain why.',
        ];

        return implode("\n", $lines);
    }

    protected function extractBatchProposals(array $parsed): array
    {
        $raw = $parsed['fields'] ?? $parsed['proposals'] ?? $parsed;
        $out = [];

        if (array_is_list($raw)) {
            foreach ($raw as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $field = (string) ($item['field'] ?? $item['name'] ?? '');
                if ($field !== '') {
                    $out[$field] = $item;
                }
            }
            return $out;
        }

        foreach ((array) $raw as $field => $proposal) {
            $out[(string) $field] = is_array($proposal) ? $proposal : ['value' => (string) $proposal, 'rationale' => ''];
        }

        return $out;
    }

    protected function formatProposalResult(string $field, array $input, array $parsed, array $response, string $model): array
    {
        $value = (string) ($parsed['value'] ?? $parsed[$field] ?? '');
        $modelValue = $value;
        if ($field === 'slug') {
            $value = $this->normalizeSlug($value);
        }
        if ($field === 'featured_image_file_name') {
            $value = $this->normalizeFileName($value);
        }
        if ($this->isRankMathTextField($field)) {
            $value = $this->fitRankMathText($field, $this->normalizeRankMathText($value));
        }

        $rationale = trim((string) ($parsed['rationale'] ?? $parsed['why'] ?? ''));
        if ($this->isRankMathTextField($field)) {
            $constraints = $this->rankMathConstraintsForField($field);
            $limitLine = 'Generated within Rank Math guideline: ' . $constraints['target_min'] . '-' . $constraints['target_max'] . ' characters; must not exceed ' . $constraints['absolute_max'] . '.';
            if ($modelValue !== $value) {
                $limitLine .= ' The package normalized the model output to fit that limit.';
            }
            $rationale = trim($rationale . ($rationale !== '' ? ' ' : '') . $limitLine);
        }

        return [
            'success' => true,
            'field' => $field,
            'label' => $this->fieldLabel($field),
            'value' => $value,
            'rationale' => $rationale,
            'model' => data_get($response, 'data.model', $model),
            'rules' => $this->rulesForField($field),
            'rank_math_constraints' => $this->rankMathConstraintsForField($field),
            'metric' => $this->metricForProposal($field, $input, $value),
        ];
    }

    protected function isRankMathTextField(string $field): bool
    {
        return in_array($field, ['seo_title', 'seo_description'], true);
    }

    protected function rankMathConstraintsForField(string $field): array
    {
        return match ($field) {
            'seo_title' => [
                'provider' => 'rank_math',
                'field' => 'seo_title',
                'target_min' => 50,
                'target_max' => 60,
                'absolute_max' => 60,
                'hard_warning_at' => 65,
                'counter' => 'characters',
                'requirement' => 'Return a visible SEO title between 50 and 60 characters and never over 60 characters.',
            ],
            'seo_description' => [
                'provider' => 'rank_math',
                'field' => 'seo_description',
                'target_min' => 120,
                'target_max' => 160,
                'absolute_max' => 160,
                'hard_warning_at' => 170,
                'counter' => 'characters',
                'requirement' => 'Return a visible SEO description between 120 and 160 characters and never over 160 characters.',
            ],
            default => [],
        };
    }

    protected function currentValueForField(array $input, string $field): string
    {
        $page = (array) ($input['page'] ?? []);
        return (string) ($page[$field] ?? '');
    }

    protected function normalizeRankMathText(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?: $value;
        return trim($value);
    }

    protected function fitRankMathText(string $field, string $value): string
    {
        $constraints = $this->rankMathConstraintsForField($field);
        $max = (int) ($constraints['absolute_max'] ?? 0);
        if ($max <= 0 || mb_strlen($value) <= $max) {
            return $value;
        }

        $cut = mb_substr($value, 0, $max);
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false && $lastSpace >= max(24, (int) floor($max * 0.72))) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return trim($cut, " \t\n\r\0\x0B-|,;:./");
    }

    protected function metricForProposal(string $field, array $input, string $value): ?array
    {
        if (!$this->isRankMathTextField($field) || !class_exists(WordPressSeoScorePreviewService::class)) {
            return null;
        }

        $page = (array) ($input['page'] ?? []);
        $draft = [$field => $value];
        $report = app(WordPressSeoScorePreviewService::class)->analyze($page, $draft);
        return (array) ($report['metrics'][$field] ?? []);
    }

    protected function resolveModel(string $requested, object $catalog): string
    {
        if ($requested !== '') {
            $found = $catalog->findSiteEnabled($requested, true);
            if (is_array($found) && isset($found['id'])) {
                return (string) $found['id'];
            }
        }

        $fallback = $catalog->findSiteEnabled('claude-sonnet-4-6', true)['id']
            ?? $catalog->findSiteEnabled('claude-sonnet-4-20250514', true)['id']
            ?? ($catalog->siteEnabledEntries(true)[0]['id'] ?? 'gpt-4o-mini');

        return (string) $fallback;
    }

    protected function parseResponse(array $response): ?array
    {
        $content = trim((string) data_get($response, 'data.content', ''));
        if ($content === '') {
            return null;
        }

        $parsed = json_decode($content, true);
        if (is_array($parsed)) {
            return $parsed;
        }

        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $parsed = json_decode(substr($content, $start, $end - $start + 1), true);
            if (is_array($parsed)) {
                return $parsed;
            }
        }

        return null;
    }

    protected function normalizeSlug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/^https?:\/\/[^\/]+\//', '', $value) ?: $value;
        $value = trim($value, '/');
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?: $value;
        return trim($value, '-');
    }

    protected function normalizeFileName(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }
        $extension = '';
        if (preg_match('/\.([a-z0-9]{2,5})$/', $value, $match)) {
            $extension = '.' . $match[1];
            $value = substr($value, 0, -strlen($extension));
        }
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?: $value;
        $value = trim($value, '-');
        return $value . $extension;
    }
}
