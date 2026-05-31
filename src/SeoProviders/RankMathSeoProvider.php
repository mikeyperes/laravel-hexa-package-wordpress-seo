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
        return in_array($feature, ["seo_title", "seo_description", "featured_image"], true);
    }

    public function inventoryPages(array $target, array $filters = []): array
    {
        $postTypes = array_values(array_filter(array_map("strval", (array) ($filters["post_types"] ?? config("wordpress-seo.inventory.post_types", ["page"])))));
        $statuses = array_values(array_filter(array_map("strval", (array) ($filters["statuses"] ?? config("wordpress-seo.inventory.statuses", ["publish"])))));
        $perPage = max(1, (int) ($filters["per_page"] ?? config("wordpress-seo.inventory.per_page", 250)));
        $pageId = isset($filters["page_id"]) ? (int) $filters["page_id"] : 0;

        $php = implode("", [
            "\$postTypes=" . var_export($postTypes, true) . ";",
            "\$statuses=" . var_export($statuses, true) . ";",
            "\$perPage=" . var_export($perPage, true) . ";",
            "\$pageId=" . var_export($pageId, true) . ";",
            "\$decode=function(\$value){ return html_entity_decode((string) \$value, ENT_QUOTES | ENT_HTML5, get_bloginfo(\"charset\") ?: \"UTF-8\"); };",
            "\$args=[\"post_type\"=>\$postTypes,\"post_status\"=>\$statuses,\"posts_per_page\"=>\$perPage,\"orderby\"=>\"ID\",\"order\"=>\"ASC\"];",
            "if (\$pageId > 0) { \$args[\"post__in\"] = [\$pageId]; \$args[\"posts_per_page\"] = 1; }",
            "\$posts=get_posts(\$args);",
            "\$pages=[];",
            "foreach (\$posts as \$post) {",
            "  \$contentText=\$decode(wp_strip_all_tags(strip_shortcodes((string) \$post->post_content)));",
            "  \$thumbId=(int) get_post_thumbnail_id(\$post);",
            "  \$thumbUrl=\$thumbId > 0 ? (string) wp_get_attachment_image_url(\$thumbId, \"medium_large\") : \"\";",
            "  if (\$thumbUrl === \"\" && \$thumbId > 0) { \$thumbUrl=(string) wp_get_attachment_image_url(\$thumbId, \"full\"); }",
            "  \$thumbAlt=\$thumbId > 0 ? \$decode((string) get_post_meta(\$thumbId, \"_wp_attachment_image_alt\", true)) : \"\";",
            "  \$pages[]=[",
            "    \"id\"=>(int) \$post->ID,",
            "    \"post_type\"=>(string) \$post->post_type,",
            "    \"status\"=>(string) \$post->post_status,",
            "    \"title\"=>\$decode(get_the_title(\$post)),",
            "    \"slug\"=>(string) \$post->post_name,",
            "    \"url\"=>get_permalink(\$post),",
            "    \"modified_gmt\"=>(string) \$post->post_modified_gmt,",
            "    \"featured_image_id\"=>\$thumbId,",
            "    \"featured_image\"=>\$thumbUrl,",
            "    \"featured_image_url\"=>\$thumbUrl,",
            "    \"featured_image_alt\"=>\$thumbAlt,",
            "    \"excerpt\"=>\$decode((string) \$post->post_excerpt),",
            "    \"content_text\"=>\$contentText,",
            "    \"seo_title\"=>\$decode((string) get_post_meta(\$post->ID, \"rank_math_title\", true)),",
            "    \"seo_description\"=>\$decode((string) get_post_meta(\$post->ID, \"rank_math_description\", true)),",
            "  ];",
            "}",
            "echo \"HEXA_RANKMATH_INVENTORY:\" . wp_json_encode([\"success\"=>true,\"message\"=>count(\$pages) . \" page(s) loaded.\",\"pages\"=>\$pages]);",
        ]);

        $result = $this->wp->evaluatePhp($target, $php);
        if (!($result["success"] ?? false)) {
            return ["success" => false, "message" => (string) ($result["message"] ?? "Rank Math inventory failed."), "pages" => []];
        }

        $payload = $this->decodeMarkedPayload((string) ($result["stdout"] ?? ""), "HEXA_RANKMATH_INVENTORY:");
        if (!is_array($payload)) {
            return ["success" => false, "message" => "Failed to parse Rank Math inventory output.", "pages" => []];
        }

        $payload["pages"] = array_values(array_map(fn (array $page) => $this->normalizePagePayload($page), array_filter((array) ($payload["pages"] ?? []), "is_array")));

        return $payload;
    }

    public function readPage(array $target, int $pageId): array
    {
        $inventory = $this->inventoryPages($target, ["page_id" => $pageId, "per_page" => 1]);
        $page = $inventory["pages"][0] ?? null;

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
            "\$decode=function(\$value){ return html_entity_decode((string) \$value, ENT_QUOTES | ENT_HTML5, get_bloginfo(\"charset\") ?: \"UTF-8\"); };",
            "\$post=get_post(\$pageId);",
            "if (!\$post) { echo \"HEXA_RANKMATH_WRITE:\" . wp_json_encode([\"success\"=>false,\"message\"=>\"Page not found.\"]); return; }",
            "update_post_meta(\$pageId, \"rank_math_title\", \$seoTitle);",
            "update_post_meta(\$pageId, \"rank_math_description\", \$seoDescription);",
            "clean_post_cache(\$pageId);",
            "echo \"HEXA_RANKMATH_WRITE:\" . wp_json_encode([\"success\"=>true,\"message\"=>\"Rank Math SEO fields updated.\",\"page\"=>[\"id\"=>\$pageId,\"url\"=>get_permalink(\$pageId),\"seo_title\"=>\$decode((string) get_post_meta(\$pageId, \"rank_math_title\", true)),\"seo_description\"=>\$decode((string) get_post_meta(\$pageId, \"rank_math_description\", true))]]);",
        ]);

        $result = $this->wp->evaluatePhp($target, $php);
        if (!($result["success"] ?? false)) {
            return ["success" => false, "message" => (string) ($result["message"] ?? "Rank Math write failed.")];
        }

        $response = $this->decodeMarkedPayload((string) ($result["stdout"] ?? ""), "HEXA_RANKMATH_WRITE:");
        if (!is_array($response)) {
            return ["success" => false, "message" => "Failed to parse Rank Math write output."];
        }

        if (isset($response["page"]) && is_array($response["page"])) {
            $response["page"] = $this->normalizePagePayload($response["page"]);
        }

        return $response;
    }

    protected function normalizePagePayload(array $page): array
    {
        foreach (["title", "excerpt", "content_text", "seo_title", "seo_description", "featured_image", "featured_image_url", "featured_image_alt"] as $field) {
            if (array_key_exists($field, $page)) {
                $page[$field] = $this->decodeText((string) ($page[$field] ?? ""));
            }
        }

        return $page;
    }

    protected function decodeText(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, "UTF-8");
    }

    protected function decodeMarkedPayload(string $stdout, string $marker): ?array
    {
        $position = strrpos($stdout, $marker);
        if ($position === false) {
            return null;
        }

        $json = trim(substr($stdout, $position + strlen($marker)));
        if ($json === "") {
            return null;
        }

        $payload = json_decode($json, true);
        return is_array($payload) ? $payload : null;
    }
}
