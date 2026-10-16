<?php

namespace hexa_package_wordpress_seo\Services;

use hexa_package_wordpress\Services\WordPressManagerService;
use hexa_package_wordpress_seo\Services\Indexability\PublicUrlInspector;

/**
 * Rank Math's built-in llms.txt: read its state, switch it on and fill it in
 * from the same content types the Rank Math sitemap lists, or switch it off.
 * Every change is checked against the served https://<site>/llms.txt.
 */
class RankMathLlmsTxtService
{
    private const MARKER = "HEXA_LLMS_TXT:";

    public function __construct(
        protected WordPressManagerService $wp,
        protected PublicUrlInspector $inspector,
    ) {
    }

    /** @return array<string, mixed> */
    public function status(array $target): array
    {
        $state = $this->siteState($target);
        if (!($state["success"] ?? false)) {
            return $state;
        }

        $served = $this->served((string) $state["llms_url"]);
        $problems = $this->problems($state, $served);
        unset($state["user_logins"]);

        return $state + ["served" => $served, "problems" => $problems];
    }

    /**
     * Switch the module on and save its settings. Unset options default to the
     * content types and categories the Rank Math sitemap includes.
     *
     * The file's heading is Rank Math's site name (also used in its structured
     * data), so site_name is saved there; summary is the line under it.
     *
     * @param array{post_types?: list<string>, taxonomies?: list<string>, limit?: int, summary?: string, extra_content?: string, site_name?: string} $options
     * @return array<string, mixed>
     */
    public function apply(array $target, array $options = []): array
    {
        $before = $this->siteState($target);
        if (!($before["success"] ?? false)) {
            return $before;
        }

        $settings = [
            "llms_post_types" => array_values($options["post_types"] ?? $before["sitemap_post_types"]),
            "llms_taxonomies" => array_values($options["taxonomies"] ?? $before["sitemap_taxonomies"]),
            "llms_limit" => (int) ($options["limit"] ?? config("wordpress-seo.llms_txt.limit", 100)),
        ];
        foreach (["summary" => "llms_summary", "extra_content" => "llms_extra_content"] as $option => $key) {
            if (array_key_exists($option, $options)) {
                $settings[$key] = trim((string) $options[$option]);
            }
        }
        $siteName = trim((string) ($options["site_name"] ?? ""));

        $php = "\$settings=" . var_export($settings, true) . ";\$siteName=" . var_export($siteName, true) . ";" . <<<'PHP'
\RankMath\Helper::update_modules(["llms-txt" => "on"]);
$general = (array) get_option("rank-math-options-general", []);
foreach ($settings as $key => $value) {
    $general[$key] = $value;
}
update_option("rank-math-options-general", $general);
if ($siteName !== "") {
    $titles = (array) get_option("rank-math-options-titles", []);
    $titles["knowledgegraph_name"] = $siteName;
    update_option("rank-math-options-titles", $titles);
}
// The module registers its address on init, which already ran in this
// request, so register it here and rebuild WordPress's address rules once.
add_rewrite_tag("%llms_txt%", "1");
add_rewrite_rule('^llms\.txt$', "index.php?llms_txt=1", "top");
flush_rewrite_rules(false);
// LiteSpeed may still hold the old answer for this address; clear only it.
if (has_action("litespeed_purge_url")) {
    ob_start();
    do_action("litespeed_purge_url", home_url("/llms.txt"));
    ob_end_clean();
}
echo "HEXA_LLMS_TXT:" . wp_json_encode(["success" => true]);
PHP;

        $write = $this->run($target, $php);
        if (!($write["success"] ?? false)) {
            return $write;
        }

        $after = $this->status($target);

        return [
            "success" => (bool) (($after["module_active"] ?? false) && ($after["served"]["ok"] ?? false)),
            "saved" => $settings + ($siteName !== "" ? ["site_name" => $siteName] : []),
            "before" => $this->summary($before),
            "after" => $after,
        ];
    }

    /** @return array<string, mixed> */
    public function disable(array $target): array
    {
        $write = $this->run($target, <<<'PHP'
\RankMath\Helper::update_modules(["llms-txt" => "off"]);
// The module already added its address rule in this request; drop it before
// rebuilding WordPress's address rules.
global $wp_rewrite;
unset($wp_rewrite->extra_rules_top['^llms\.txt$']);
flush_rewrite_rules(false);
// LiteSpeed may still hold the old answer for this address; clear only it.
if (has_action("litespeed_purge_url")) {
    ob_start();
    do_action("litespeed_purge_url", home_url("/llms.txt"));
    ob_end_clean();
}
echo "HEXA_LLMS_TXT:" . wp_json_encode(["success" => true]);
PHP);
        if (!($write["success"] ?? false)) {
            return $write;
        }

        $after = $this->status($target);

        return ["success" => !($after["module_active"] ?? true) && !($after["served"]["ok"] ?? true), "after" => $after];
    }

    /** @return array<string, mixed> */
    protected function siteState(array $target): array
    {
        $state = $this->run($target, <<<'PHP'
if (!class_exists("\\RankMath\\Helper")) {
    echo "HEXA_LLMS_TXT:" . wp_json_encode(["success" => false, "message" => "Rank Math is not active on this site."]);
    return;
}
$H = "\\RankMath\\Helper";
$postTypes = [];
foreach ((array) $H::get_accessible_post_types() as $type) {
    if ($type !== "attachment" && $H::get_settings("sitemap.pt_" . $type . "_sitemap")) {
        $postTypes[] = $type;
    }
}
$taxonomies = [];
foreach (array_keys((array) $H::get_accessible_taxonomies()) as $taxonomy) {
    if ($H::get_settings("sitemap.tax_" . $taxonomy . "_sitemap")) {
        $taxonomies[] = $taxonomy;
    }
}
echo "HEXA_LLMS_TXT:" . wp_json_encode([
    "success" => true,
    "llms_url" => home_url("/llms.txt"),
    "module_active" => in_array("llms-txt", (array) $H::get_active_modules(), true),
    "rank_math_version" => defined("RANK_MATH_VERSION") ? RANK_MATH_VERSION : null,
    "settings" => [
        "post_types" => array_values((array) $H::get_settings("general.llms_post_types", [])),
        "taxonomies" => array_values((array) $H::get_settings("general.llms_taxonomies", [])),
        "limit" => (int) $H::get_settings("general.llms_limit", 0),
        "summary" => (string) $H::get_settings("general.llms_summary", ""),
        "extra_content" => (string) $H::get_settings("general.llms_extra_content", ""),
    ],
    "site_name" => (string) $H::get_settings("titles.knowledgegraph_name", get_bloginfo("name")),
    "user_logins" => array_map("strtolower", (array) get_users(["fields" => "user_login", "number" => 200])),
    "sitemap_post_types" => $postTypes,
    "sitemap_taxonomies" => $taxonomies,
    "physical_file" => file_exists(ABSPATH . "llms.txt"),
]);
PHP);

        return $state;
    }

    /** @return array<string, mixed> */
    protected function served(string $url): array
    {
        $check = $this->inspector->inspect($url, (array) config("wordpress-seo.indexability", []) + ["include_body" => true]);
        $body = (string) ($check["_body"] ?? "");
        $status = (int) ($check["status_code"] ?? 0);
        $isText = str_starts_with((string) ($check["content_type"] ?? ""), "text/plain");

        return [
            "ok" => $status === 200 && $isText && $body !== "",
            "status" => $status,
            "content_type" => $check["content_type"] ?? null,
            "links" => preg_match_all('/^\s*-\s*\[[^\]]*\]\(https?:\/\/[^)]+\)/m', $body),
            "sections" => preg_match_all('/^##\s+/m', $body),
            "first_lines" => $isText ? array_slice(preg_split('/\R/', trim($body)) ?: [], 0, 6) : [],
            "error" => $check["error"] ?? null,
        ];
    }

    /**
     * What keeps llms.txt from being complete, in plain words.
     *
     * @return list<string>
     */
    protected function problems(array $state, array $served): array
    {
        $problems = [];
        $siteName = strtolower(trim((string) ($state["site_name"] ?? "")));
        if (!($state["module_active"] ?? false)) {
            $problems[] = "llms.txt is switched off in Rank Math.";
        }
        if ($state["physical_file"] ?? false) {
            $problems[] = "An llms.txt file on the server overrides Rank Math's.";
        }
        if (($state["module_active"] ?? false) && !($served["ok"] ?? false)) {
            $problems[] = "llms.txt is on but the site does not serve it (status " . ($served["status"] ?? 0) . ").";
        }
        $placeholders = array_map("strtolower", (array) config("wordpress-seo.llms_txt.placeholder_site_names", []));
        if ($siteName === "" || in_array($siteName, array_merge($placeholders, (array) ($state["user_logins"] ?? [])), true)) {
            $problems[] = "Rank Math's site name, the llms.txt heading, is \"" . ($state["site_name"] ?? "") . "\" (empty, a placeholder or a login name).";
        }
        if (trim((string) ($state["settings"]["summary"] ?? "")) === "") {
            $problems[] = "No summary line under the llms.txt heading.";
        }

        return $problems;
    }

    /** @return array<string, mixed> */
    protected function summary(array $state): array
    {
        return [
            "module_active" => $state["module_active"] ?? null,
            "site_name" => $state["site_name"] ?? null,
            "settings" => $state["settings"] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    protected function run(array $target, string $php): array
    {
        $result = $this->wp->evaluatePhp($target, $php);
        if (!($result["success"] ?? false)) {
            return ["success" => false, "message" => (string) ($result["message"] ?? "The site did not run the llms.txt step.")];
        }

        $stdout = (string) ($result["stdout"] ?? "");
        $position = strrpos($stdout, self::MARKER);
        $payload = $position === false ? null : json_decode(trim(substr($stdout, $position + strlen(self::MARKER))), true);

        return is_array($payload) ? $payload : ["success" => false, "message" => "The site's llms.txt reply could not be read."];
    }
}
