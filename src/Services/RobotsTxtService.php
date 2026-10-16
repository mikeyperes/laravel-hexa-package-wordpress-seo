<?php

namespace hexa_package_wordpress_seo\Services;

use hexa_package_wordpress\Services\WordPressManagerService;
use hexa_package_wordpress_seo\Services\Indexability\PublicUrlInspector;
use hexa_package_wordpress_seo\Services\Indexability\RobotsTxtPolicy;

/**
 * One site's robots.txt, kept in Rank Math's robots.txt setting. Reads what is
 * served and why (an old file on the server overrides Rank Math), builds the
 * standard file (WordPress basics, AI crawlers named and allowed, the pages
 * blocked on request, Rank Math's sitemap) and applies it.
 *
 * Rank Math's custom text replaces its automatic sitemap line, so the built
 * file always carries the sitemap itself.
 */
class RobotsTxtService
{
    private const MARKER = "HEXA_ROBOTS_TXT:";

    public function __construct(
        protected WordPressManagerService $wp,
        protected PublicUrlInspector $inspector,
        protected RobotsTxtPolicy $policy,
    ) {
    }

    /** @return array<string, mixed> */
    public function status(array $target): array
    {
        $state = $this->siteState($target);
        if (!($state["success"] ?? false)) {
            return $state;
        }

        // What the site itself serves (a one-off query string skips page
        // caches), and what a cache in front of it hands out.
        $served = $this->served($state["robots_url"] . "?hws-check=" . time());
        $cached = $this->served((string) $state["robots_url"]);
        $policy = $this->policy->parse((string) $served["body"]);
        $home = (string) $state["home_url"];
        // The saved rules, not a cached copy, are what later changes build on.
        $saved = $state["file_exists"] ? (string) $state["file_content"] : ((string) $state["rank_math_content"] !== "" ? (string) $state["rank_math_content"] : (string) $served["body"]);

        return [
            "success" => true,
            "robots_url" => $state["robots_url"],
            "source" => $state["file_exists"] ? "file on the server (Rank Math is overridden)" : ($state["rank_math_content"] !== "" ? "Rank Math setting" : "WordPress default"),
            "search_engines_allowed" => (bool) $state["public"],
            "file_exists" => $state["file_exists"],
            "file_content" => $state["file_content"],
            "rank_math_content" => $state["rank_math_content"],
            "rank_math_sitemap" => $state["sitemap_url"],
            "served" => $served,
            "cached_copy_current" => $this->normalize((string) $cached["body"]) === $this->normalize((string) $served["body"]),
            "saved_content" => $saved,
            "sitemaps" => $this->sitemapChecks($policy["sitemaps"], (string) $state["sitemap_url"]),
            "homepage_allowed" => $this->allowedFor($home, $policy),
            "blocked_on_request" => $this->customBlocks($this->policy->parse($saved)),
            "ai_fetch" => $this->aiFetch($home),
        ];
    }

    /**
     * The file this site should serve, and what changes from what it serves
     * now. Pages blocked on request carry over from the current file.
     *
     * @param list<string> $block
     * @param list<string> $unblock
     * @return array<string, mixed>
     */
    public function plan(array $target, array $block = [], array $unblock = []): array
    {
        $status = $this->status($target);
        if (!($status["success"] ?? false)) {
            return $status;
        }

        $current = $this->policy->parse((string) $status["saved_content"]);
        $paths = array_values(array_diff(
            array_unique(array_merge($status["blocked_on_request"], array_map([$this, "normalizePath"], $block))),
            array_map([$this, "normalizePath"], $unblock),
        ));
        $content = $this->build($paths, (string) $status["rank_math_sitemap"]);

        return [
            "success" => true,
            "can_apply" => $status["search_engines_allowed"],
            "blocker" => $status["search_engines_allowed"] ? null : "WordPress is set to hide the whole site from search engines; that is changed on purpose elsewhere, never here.",
            "removes_server_file" => $status["file_exists"],
            "blocked_on_request" => $paths,
            "dropped" => $this->dropped($current, $paths, (string) $status["rank_math_sitemap"]),
            "current" => (string) $status["saved_content"],
            "content" => $content,
            "status" => $status,
        ];
    }

    /**
     * Save the planned file in Rank Math, remove an old server file that
     * would override it, then read the served file back.
     *
     * @param list<string> $block
     * @param list<string> $unblock
     * @return array<string, mixed>
     */
    public function apply(array $target, array $block = [], array $unblock = []): array
    {
        $plan = $this->plan($target, $block, $unblock);
        if (!($plan["success"] ?? false) || !($plan["can_apply"] ?? false)) {
            return ["success" => false, "message" => $plan["blocker"] ?? $plan["message"] ?? "robots.txt plan failed."] + $plan;
        }

        $php = "\$content=" . var_export($plan["content"], true) . ";" . <<<'PHP'
$general = (array) get_option("rank-math-options-general", []);
$general["robots_txt_content"] = $content;
update_option("rank-math-options-general", $general);
$file = ABSPATH . "robots.txt";
$removed = false;
if (file_exists($file)) {
    $removed = @unlink($file);
    if (!$removed) {
        echo "HEXA_ROBOTS_TXT:" . wp_json_encode(["success" => false, "message" => "Saved in Rank Math, but the old robots.txt file on the server could not be removed: " . $file]);
        return;
    }
}
// LiteSpeed may still hold the old answer for this address; clear only it.
if (has_action("litespeed_purge_url")) {
    ob_start();
    do_action("litespeed_purge_url", home_url("/robots.txt"));
    ob_end_clean();
}
echo "HEXA_ROBOTS_TXT:" . wp_json_encode(["success" => true, "removed_file" => $removed]);
PHP;

        $write = $this->run($target, $php);
        if (!($write["success"] ?? false)) {
            return $write;
        }

        $after = $this->status($target);
        $matches = $this->normalize((string) ($after["served"]["body"] ?? "")) === $this->normalize($plan["content"]);
        $cachedCurrent = (bool) ($after["cached_copy_current"] ?? false);

        return [
            "success" => $matches,
            "removed_server_file" => (bool) ($write["removed_file"] ?? false),
            "served_matches" => $matches,
            "cached_copy_current" => $cachedCurrent,
            "message" => match (true) {
                !$matches => "Saved, but the site does not serve the new robots.txt.",
                !$cachedCurrent => "The site serves the new robots.txt; a cache in front of it (such as Cloudflare) still shows the old copy until it expires.",
                default => "robots.txt is served from Rank Math.",
            },
            "before" => $plan["current"],
            "after" => $after,
        ];
    }

    /** @param list<string> $blocked */
    public function build(array $blocked, string $sitemapUrl): string
    {
        $config = (array) config("wordpress-seo.robots_txt", []);
        $lines = ["# Managed in Rank Math by Hexa Web Systems.", "User-agent: *"];
        foreach ((array) ($config["ai_crawlers"] ?? []) as $agent) {
            $lines[] = "User-agent: " . $agent;
        }
        foreach ((array) ($config["base_rules"] ?? []) as $rule) {
            $lines[] = $rule;
        }
        if ($blocked !== []) {
            $lines[] = "# Blocked on request";
            foreach ($blocked as $path) {
                $lines[] = "Disallow: " . $path;
            }
        }
        if ($sitemapUrl !== "") {
            $lines[] = "";
            $lines[] = "Sitemap: " . $sitemapUrl;
        }

        return implode("\n", $lines) . "\n";
    }

    /** Disallow rules for every agent, other than the standard ones. */
    protected function customBlocks(array $policy): array
    {
        $standard = array_map(fn (string $rule): string => strtolower(trim($rule)), (array) config("wordpress-seo.robots_txt.base_rules", []));
        $paths = [];
        foreach ((array) ($policy["groups"] ?? []) as $group) {
            if (!in_array("*", (array) ($group["agents"] ?? []), true)) {
                continue;
            }
            foreach ((array) ($group["rules"] ?? []) as $rule) {
                $line = $rule["directive"] . ": " . $rule["pattern"];
                if ($rule["directive"] === "disallow" && $rule["pattern"] !== "" && !in_array(strtolower($line), $standard, true)) {
                    $paths[] = $this->normalizePath((string) $rule["pattern"]);
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /** Rules in the current file that the standard file does not keep. */
    protected function dropped(array $current, array $kept, string $sitemapUrl): array
    {
        $standard = array_map(fn (string $rule): string => strtolower(trim($rule)), (array) config("wordpress-seo.robots_txt.base_rules", []));
        $aiAgents = array_map("strtolower", (array) config("wordpress-seo.robots_txt.ai_crawlers", []));
        $dropped = [];
        foreach ((array) ($current["groups"] ?? []) as $group) {
            $agents = (array) ($group["agents"] ?? []);
            $known = array_diff($agents, array_merge(["*"], $aiAgents)) === [];
            foreach ((array) ($group["rules"] ?? []) as $rule) {
                $line = strtolower($rule["directive"] . ": " . $rule["pattern"]);
                $isKept = in_array($line, $standard, true) || ($rule["directive"] === "disallow" && in_array($this->normalizePath((string) $rule["pattern"]), $kept, true));
                // "Allow: /" only repeats the default, so nothing is lost.
                $isNoOp = $rule["directive"] === "allow" && $rule["pattern"] === "/";
                if (!$known || (!$isKept && !$isNoOp && $rule["pattern"] !== "")) {
                    $dropped[] = "User-agent: " . implode(", ", $agents) . " | " . ucfirst($rule["directive"]) . ": " . $rule["pattern"];
                }
            }
        }
        foreach ((array) ($current["sitemaps"] ?? []) as $sitemap) {
            if ($sitemap !== $sitemapUrl) {
                $dropped[] = "Sitemap: " . $sitemap;
            }
        }

        return array_values(array_unique($dropped));
    }

    /** @return array<string, mixed> */
    protected function siteState(array $target): array
    {
        return $this->run($target, <<<'PHP'
$sitemap = "";
if (class_exists("\\RankMath\\Helper") && in_array("sitemap", (array) \RankMath\Helper::get_active_modules(), true) && class_exists("\\RankMath\\Sitemap\\Router")) {
    $sitemap = \RankMath\Sitemap\Router::get_base_url(\RankMath\Sitemap\Sitemap::get_sitemap_index_slug() . ".xml");
} elseif (function_exists("wp_sitemaps_get_server") && wp_sitemaps_get_server()->sitemaps_enabled()) {
    $sitemap = home_url("/wp-sitemap.xml");
}
$file = ABSPATH . "robots.txt";
echo "HEXA_ROBOTS_TXT:" . wp_json_encode([
    "success" => class_exists("\\RankMath\\Helper"),
    "message" => class_exists("\\RankMath\\Helper") ? null : "Rank Math is not active on this site.",
    "home_url" => home_url("/"),
    "robots_url" => home_url("/robots.txt"),
    "public" => (int) get_option("blog_public") === 1,
    "file_exists" => file_exists($file),
    "file_content" => file_exists($file) ? (string) file_get_contents($file) : "",
    "rank_math_content" => class_exists("\\RankMath\\Helper") ? (string) \RankMath\Helper::get_settings("general.robots_txt_content", "") : "",
    "sitemap_url" => $sitemap,
]);
PHP);
    }

    /** @return array<string, mixed> */
    protected function served(string $url): array
    {
        $check = $this->inspector->inspect($url, (array) config("wordpress-seo.indexability", []) + ["include_body" => true]);

        return [
            "status" => (int) ($check["status_code"] ?? 0),
            "body" => (string) ($check["_body"] ?? ""),
            "error" => $check["error"] ?? null,
        ];
    }

    /** @return list<array<string, mixed>> */
    protected function sitemapChecks(array $listed, string $rankMathSitemap): array
    {
        $checks = [];
        foreach ($listed as $url) {
            $check = $this->inspector->inspect($url, (array) config("wordpress-seo.indexability", []));
            $checks[] = [
                "url" => $url,
                "status" => (int) ($check["status_code"] ?? 0),
                "final_url" => $check["final_url"] ?? null,
                "is_rank_math_sitemap" => $rankMathSitemap !== "" && rtrim((string) ($check["final_url"] ?? $url), "/") === rtrim($rankMathSitemap, "/"),
            ];
        }

        return $checks;
    }

    /** @return array<string, bool> */
    protected function allowedFor(string $url, array $policy): array
    {
        $result = ["Googlebot" => $this->policy->allows($url, $policy, "Googlebot")["allowed"]];
        foreach ((array) config("wordpress-seo.robots_txt.ai_crawlers", []) as $agent) {
            $result[$agent] = $this->policy->allows($url, $policy, $agent)["allowed"];
        }

        return $result;
    }

    /**
     * The homepage fetched as each AI crawler, to catch a firewall that blocks
     * them whatever robots.txt says. Firewalls that verify crawlers by address
     * may block this test but not the real crawler, so a block is a warning.
     *
     * @return array<string, int>
     */
    protected function aiFetch(string $home): array
    {
        $options = (array) config("wordpress-seo.indexability", []);
        $result = [];
        foreach ((array) config("wordpress-seo.robots_txt.ai_fetch_agents", []) as $agent => $userAgent) {
            $result[$agent] = (int) ($this->inspector->inspect($home, ["user_agent" => $userAgent] + $options)["status_code"] ?? 0);
            usleep(max(0, (int) ($options["delay_ms"] ?? 1000)) * 1000);
        }

        return $result;
    }

    protected function normalizePath(string $path): string
    {
        $path = trim($path);
        if (preg_match('~^https?://~i', $path)) {
            $path = (string) (parse_url($path, PHP_URL_PATH) ?: "/") . (($query = parse_url($path, PHP_URL_QUERY)) ? "?" . $query : "");
        }

        return str_starts_with($path, "/") || str_starts_with($path, "*") ? $path : "/" . $path;
    }

    protected function normalize(string $content): string
    {
        return trim(str_replace("\r\n", "\n", $content));
    }

    /** @return array<string, mixed> */
    protected function run(array $target, string $php): array
    {
        $result = $this->wp->evaluatePhp($target, $php);
        if (!($result["success"] ?? false)) {
            return ["success" => false, "message" => (string) ($result["message"] ?? "The site did not run the robots.txt step.")];
        }

        $stdout = (string) ($result["stdout"] ?? "");
        $position = strrpos($stdout, self::MARKER);
        $payload = $position === false ? null : json_decode(trim(substr($stdout, $position + strlen(self::MARKER))), true);

        return is_array($payload) ? $payload : ["success" => false, "message" => "The site's robots.txt reply could not be read."];
    }
}
