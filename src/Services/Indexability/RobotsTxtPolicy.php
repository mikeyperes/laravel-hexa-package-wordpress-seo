<?php

namespace hexa_package_wordpress_seo\Services\Indexability;

final class RobotsTxtPolicy
{
    public function parse(string $contents): array
    {
        $groups = [];
        $agents = [];
        $rules = [];
        $sitemaps = [];

        $flush = static function () use (&$groups, &$agents, &$rules): void {
            if ($agents !== []) {
                $groups[] = [
                    "agents" => array_values(array_unique($agents)),
                    "rules" => $rules,
                ];
            }

            $agents = [];
            $rules = [];
        };

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim((string) preg_replace('/\s*#.*$/', '', (string) $line));
            if ($line === '') {
                if ($rules !== []) {
                    $flush();
                }
                continue;
            }

            if (!str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'sitemap') {
                if ($this->isHttpUrl($value)) {
                    $sitemaps[] = $value;
                }
                continue;
            }

            if ($field === 'user-agent') {
                if ($rules !== []) {
                    $flush();
                }
                if ($value !== '') {
                    $agents[] = strtolower($value);
                }
                continue;
            }

            if (in_array($field, ['allow', 'disallow'], true) && $agents !== []) {
                $rules[] = [
                    "directive" => $field,
                    "pattern" => $value,
                ];
            }
        }

        $flush();

        return [
            "groups" => $groups,
            "sitemaps" => array_values(array_unique($sitemaps)),
            "sha256" => hash('sha256', $contents),
        ];
    }

    public function allows(string $url, array $policy, string $userAgent = 'Googlebot'): array
    {
        $userAgent = strtolower(trim($userAgent));
        $selected = [];
        $bestAgentScore = -1;

        foreach ((array) ($policy['groups'] ?? []) as $group) {
            if (!is_array($group)) {
                continue;
            }

            foreach ((array) ($group['agents'] ?? []) as $agent) {
                $agent = strtolower(trim((string) $agent));
                $matches = $agent === '*' || ($agent !== '' && str_contains($userAgent, $agent));
                if (!$matches) {
                    continue;
                }

                $score = $agent === '*' ? 0 : strlen($agent);
                if ($score > $bestAgentScore) {
                    $selected = [(array) ($group['rules'] ?? [])];
                    $bestAgentScore = $score;
                } elseif ($score === $bestAgentScore) {
                    $selected[] = (array) ($group['rules'] ?? []);
                }
            }
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        $query = (string) (parse_url($url, PHP_URL_QUERY) ?: '');
        if ($query !== '') {
            $path .= '?' . $query;
        }

        $matched = null;
        $matchedLength = -1;

        foreach ($selected as $rules) {
            foreach ($rules as $rule) {
                if (!is_array($rule)) {
                    continue;
                }

                $directive = (string) ($rule['directive'] ?? '');
                $pattern = (string) ($rule['pattern'] ?? '');
                if ($directive === 'disallow' && $pattern === '') {
                    continue;
                }
                if (!$this->matches($path, $pattern)) {
                    continue;
                }

                $length = strlen(str_replace(['*', '$'], '', $pattern));
                if (
                    $length > $matchedLength
                    || ($length === $matchedLength && $directive === 'allow' && ($matched['directive'] ?? '') !== 'allow')
                ) {
                    $matched = [
                        "directive" => $directive,
                        "pattern" => $pattern,
                    ];
                    $matchedLength = $length;
                }
            }
        }

        return [
            "allowed" => ($matched['directive'] ?? 'allow') !== 'disallow',
            "matched_rule" => $matched,
            "user_agent" => $userAgent,
        ];
    }

    private function matches(string $path, string $pattern): bool
    {
        if ($pattern === '') {
            return true;
        }

        $endsAtPath = str_ends_with($pattern, '$');
        if ($endsAtPath) {
            $pattern = substr($pattern, 0, -1);
        }

        $regex = preg_quote($pattern, '~');
        $regex = str_replace('\\*', '.*', $regex);

        return preg_match('~^' . $regex . ($endsAtPath ? '$' : '') . '~u', $path) === 1;
    }

    private function isHttpUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true)
            && (string) parse_url($url, PHP_URL_HOST) !== '';
    }
}
