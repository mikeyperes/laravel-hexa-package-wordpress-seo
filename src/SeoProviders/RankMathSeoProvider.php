<?php

namespace hexa_package_wordpress_seo\SeoProviders;

use hexa_package_wordpress\Services\WordPressManagerService;
use hexa_package_wordpress_seo\Contracts\SeoProviderInterface;

class RankMathSeoProvider implements SeoProviderInterface
{
    public function __construct(protected WordPressManagerService $wp)
    {
    }

    public function key(): string
    {
        return "rankmath";
    }

    public function label(): string
    {
        return "Rank Math";
    }

    public function inspect(array $target): array
    {
        $free = $this->wp->inspectPlugin($target, "seo-by-rank-math", ["rank-math.php"]);
        $pro = $this->wp->inspectPlugin($target, "seo-by-rank-math-pro", ["rank-math-pro.php"]);

        $installed = (bool) (($free["plugin"]["found"] ?? false) || ($pro["plugin"]["found"] ?? false));
        $active = (bool) (($free["plugin"]["active"] ?? false) || ($pro["plugin"]["active"] ?? false));

        return [
            "success" => $installed,
            "provider" => $this->key(),
            "installed" => $installed,
            "active" => $active,
            "free" => $free["plugin"] ?? null,
            "pro" => $pro["plugin"] ?? null,
            "message" => !$installed
                ? "Rank Math is not installed."
                : ($active ? "Rank Math is installed and active." : "Rank Math is installed but inactive."),
        ];
    }

    public function supportsFeature(string $feature): bool
    {
        return in_array($feature, ["seo_title", "seo_description"], true);
    }

    public function inventoryPages(array $target, array $filters = []): array
    {
        $postTypes = array_values(array_filter(array_map("strval", (array) ($filters["post_types"] ?? config("wordpress-seo.inventory.post_types", ["page", "post"])))));
        $statuses = array_values(array_filter(array_map("strval", (array) ($filters["statuses"] ?? config("wordpress-seo.inventory.statuses", ["publish"])))));
        $perPage = max(1, (int) ($filters["per_page"] ?? config("wordpress-seo.inventory.per_page", 250)));
        $pageId = isset($filters["page_id"]) ? (int) $filters["page_id"] : 0;

        $php = implode("", [
            "\$postTypes=" . var_export($postTypes, true) . ";",
            "\$statuses=" . var_export($statuses, true) . ";",
            "\$perPage=" . var_export($perPage, true) . ";",
            "\$pageId=" . var_export($pageId, true) . ";",
            "\$args=[\"post_type\"=>\$postTypes,\"post_status\"=>\$statuses,\"posts_per_page\"=>\$perPage,\"orderby\"=>\"ID\",\"order\"=>\"ASC\"] + (\$pageId > 0 ? [\"p\"=>\$pageId] : []);",
            "\$posts=get_posts(\$args);",
            "\$rows=[];",
            "foreach (\$posts as \$post) {",
            "  \$content=wp_strip_all_tags(strip_shortcodes((string) \$post->post_content));",
            "  \$rows[]=[",
            "    \"id\"=>(int) \$post->ID,",
            "    \"post_type\"=>(string) \$post->post_type,",
            "    \"status\"=>(string) \$post->post_status,",
            "    \"title\"=>get_the_title(\$post),",
            "    \"slug\"=>(string) \$post->post_name,",
            "    \"url\"=>get_permalink(\$post),",
            "    \"modified_gmt\"=>(string) \$post->post_modified_gmt,",
            "    \"excerpt\"=>(string) \$post->post_excerpt,",
            "    \"content_text\"=>\$content,",
            "    \"seo_title\"=>(string) get_post_meta(\$post->ID, \"rank_math_title\", true),",
            "    \"seo_description\"=>(string) get_post_meta(\$post->ID, \"rank_math_description\", true),",
            "  ];",
            "}",
            "echo \"HEXA_RANKMATH_INVENTORY:\" . wp_json_encode([\"success\"=>true,\"message\"=>count(\$rows) . \" page(s) loaded.\",\"pages\"=>\$rows]);",
        ]);

        $result = $this->wp->evaluatePhp($target, $php);
        if (!($result["success"] ?? false)) {
            return ["success" => false, "message" => (string) ($result["message"] ?? "Rank Math inventory failed."), "pages" => []];
        }

        $payload = $this->decodeMarkedPayload((string) ($result["stdout"] ?? ""), "HEXA_RANKMATH_INVENTORY:");
        return is_array($payload)
            ? $payload
            : ["success" => false, "message" => "Failed to parse Rank Math inventory output.", "pages" => []];
    }

    public function readPage(array $target, int $pageId): array
    {
        $payload = $this->inventoryPages($target, ["page_id" => $pageId, "per_page" => 1]);
        $page = $payload["pages"][0] ?? null;

        return [
            "success" => is_array($page),
            "message" => is_array($page) ? "Page loaded." : "Page not found.",
            "page" => $page,
        ];
    }

    public function writePage(array $target, int $pageId, array $payload): array
    {
        $seoTitle = trim((string) ($payload["seo_title"] ?? ""));
        $seoDescription = trim((string) ($payload["seo_description"] ?? ""));

        $php = implode("", [
            "\$pageId=" . var_export($pageId, true) . ";",
            "\$seoTitle=" . var_export($seoTitle, true) . ";",
            "\$seoDescription=" . var_export($seoDescription, true) . ";",
            "\$post=get_post(\$pageId);",
            "if (!\$post) { echo \"HEXA_RANKMATH_WRITE:\" . wp_json_encode([\"success\"=>false,\"message\"=>\"Page not found.\"]); return; }",
            "update_post_meta(\$pageId, \"rank_math_title\", \$seoTitle);",
            "update_post_meta(\$pageId, \"rank_math_description\", \$seoDescription);",
            "clean_post_cache(\$pageId);",
            "echo \"HEXA_RANKMATH_WRITE:\" . wp_json_encode([\"success\"=>true,\"message\"=>\"Rank Math SEO fields updated.\",\"page\"=>[\"id\"=>\$pageId,\"url\"=>get_permalink(\$pageId),\"seo_title\"=>(string) get_post_meta(\$pageId, \"rank_math_title\", true),\"seo_description\"=>(string) get_post_meta(\$pageId, \"rank_math_description\", true)]]);",
        ]);

        $result = $this->wp->evaluatePhp($target, $php);
        if (!($result["success"] ?? false)) {
            return ["success" => false, "message" => (string) ($result["message"] ?? "Rank Math write failed.")];
        }

        $decoded = $this->decodeMarkedPayload((string) ($result["stdout"] ?? ""), "HEXA_RANKMATH_WRITE:");
        return is_array($decoded)
            ? $decoded
            : ["success" => false, "message" => "Failed to parse Rank Math write output."];
    }

    protected function decodeMarkedPayload(string $stdout, string $marker): ?array
    {
        $pos = strrpos($stdout, $marker);
        if ($pos === false) {
            return null;
        }

        $json = trim(substr($stdout, $pos + strlen($marker)));
        if ($json === "") {
            return null;
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }
}
