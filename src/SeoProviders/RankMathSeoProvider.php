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
        return in_array($feature, [
            "seo_title",
            "seo_description",
            "effective_seo_title",
            "effective_seo_description",
            "seo_title_source",
            "seo_description_source",
            "effective_seo_source",
            "featured_image",
            "seo_score",
            "focus_keyword",
            "internal_links",
        ], true);
    }

    public function inventoryPages(array $target, array $filters = []): array
    {
        $postTypes = array_values(array_filter(array_map("strval", (array) ($filters["post_types"] ?? config("wordpress-seo.inventory.post_types", ["page"])))));
        $statuses = array_values(array_filter(array_map("strval", (array) ($filters["statuses"] ?? config("wordpress-seo.inventory.statuses", ["publish"])))));
        $perPage = max(1, (int) ($filters["per_page"] ?? config("wordpress-seo.inventory.per_page", 500)));
        $pageId = isset($filters["page_id"]) ? (int) $filters["page_id"] : 0;
        $fetchEffectiveFrontend = (bool) ($filters["fetch_effective_frontend"] ?? config("wordpress-seo.inventory.fetch_effective_frontend", true));
        $effectiveFrontendLimit = max(0, (int) ($filters["effective_frontend_limit"] ?? config("wordpress-seo.inventory.effective_frontend_limit", 80)));
        $effectiveFrontendTimeout = max(1, (int) ($filters["effective_frontend_timeout"] ?? config("wordpress-seo.inventory.effective_frontend_timeout", 4)));

        $php = implode("", [
            "\$postTypes=" . var_export($postTypes, true) . ";",
            "\$statuses=" . var_export($statuses, true) . ";",
            "\$perPage=" . var_export($perPage, true) . ";",
            "\$pageId=" . var_export($pageId, true) . ";",
            "\$fetchEffectiveFrontend=" . var_export($fetchEffectiveFrontend, true) . ";",
            "\$effectiveFrontendLimit=" . var_export($effectiveFrontendLimit, true) . ";",
            "\$effectiveFrontendTimeout=" . var_export($effectiveFrontendTimeout, true) . ";",
            <<<'PHP'
$decode=function($value){ return html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, get_bloginfo("charset") ?: "UTF-8"); };
$frontId=(int) get_option("page_on_front");
$postsId=(int) get_option("page_for_posts");
$homeHost=(string) parse_url(home_url("/"), PHP_URL_HOST);
$sfpfPages=[];
foreach (wp_load_alloptions() as $key=>$value) {
    if (strpos((string)$key, "sfpf_page_") === 0 && (int)$value > 0) {
        $sfpfPages[(int)$value]=(string)$key;
    }
}
$args=[
    "post_type"=>$postTypes,
    "post_status"=>$statuses,
    "posts_per_page"=>$perPage,
    "orderby"=>["post_parent"=>"ASC","menu_order"=>"ASC","ID"=>"ASC"],
    "suppress_filters"=>false,
];
if ($pageId > 0) {
    $args["post__in"] = [$pageId];
    $args["posts_per_page"] = 1;
}
$posts=get_posts($args);
$pages=[];
$effectiveFetchCount=0;
$extractTitle=function($html) use ($decode) {
    if (preg_match('/<title\b[^>]*>(.*?)<\/title>/is', (string)$html, $m)) {
        return trim($decode(wp_strip_all_tags((string)$m[1])));
    }
    return "";
};
$extractMetaContent=function($html, $name) use ($decode) {
    $name=preg_quote((string)$name, '/');
    if (preg_match('/<meta\b(?=[^>]*\bname=["\']'.$name.'["\'])(?=[^>]*\bcontent=["\']([^"\']*)["\'])[^>]*>/is', (string)$html, $m)) {
        return trim($decode((string)$m[1]));
    }
    return "";
};
foreach ($posts as $post) {
    $contentRaw=(string) $post->post_content;
    $contentHtml=$contentRaw;
    $contentText=$decode(wp_strip_all_tags(strip_shortcodes($contentRaw)));
    $internalLinks=[];
    if (preg_match_all("~<a\\s[^>]*href=[\"']([^\"']+)[\"']~i", $contentRaw, $matches)) {
        foreach ((array) ($matches[1] ?? []) as $href) {
            $href=trim(html_entity_decode((string) $href, ENT_QUOTES | ENT_HTML5, get_bloginfo("charset") ?: "UTF-8"));
            if ($href === "" || strpos($href, "#") === 0 || stripos($href, "mailto:") === 0 || stripos($href, "tel:") === 0) {
                continue;
            }
            $absolute=$href;
            if (strpos($absolute, "/") === 0) {
                $absolute=rtrim(home_url(), "/") . $absolute;
            }
            $host=(string) parse_url($absolute, PHP_URL_HOST);
            if ($host === "" || strcasecmp($host, $homeHost) === 0) {
                $internalLinks[]=$absolute;
            }
        }
    }
    $internalLinks=array_values(array_unique($internalLinks));
    $thumbId=(int) get_post_thumbnail_id($post);
    $thumbUrl=$thumbId > 0 ? (string) wp_get_attachment_image_url($thumbId, "medium_large") : "";
    if ($thumbUrl === "" && $thumbId > 0) {
        $thumbUrl=(string) wp_get_attachment_image_url($thumbId, "full");
    }
    $thumbAlt=$thumbId > 0 ? $decode((string) get_post_meta($thumbId, "_wp_attachment_image_alt", true)) : "";
    $thumbPost=$thumbId > 0 ? get_post($thumbId) : null;
    $thumbFile=$thumbId > 0 ? (string) get_attached_file($thumbId) : "";
    $ancestors=array_reverse(get_post_ancestors($post));
    $depth=count($ancestors);
    $pathParts=[];
    foreach ($ancestors as $ancestorId) {
        $pathParts[]=(string) get_post_field("post_name", $ancestorId);
    }
    $pathParts[]=(string) $post->post_name;
    $path=implode("/", array_filter($pathParts));
    $critical=false;
    $criticalKey="";
    $criticalLabel="";
    if ((int)$post->ID === $frontId) {
        $critical=true;
        $criticalKey="homepage";
        $criticalLabel="Homepage";
    } elseif ((int)$post->ID === $postsId) {
        $critical=true;
        $criticalKey="posts_page";
        $criticalLabel="Posts page";
    } elseif (isset($sfpfPages[(int)$post->ID])) {
        $critical=true;
        $criticalKey=$sfpfPages[(int)$post->ID];
        $criticalLabel=ucwords(str_replace("_", " ", preg_replace("/^sfpf_page_/", "", $criticalKey)));
    } elseif (in_array((string)$post->post_type, ["book","organization"], true)) {
        $critical=true;
        $criticalKey=(string)$post->post_type;
        $criticalLabel=ucwords((string)$post->post_type);
    }
    $scoreRaw=get_post_meta($post->ID, "rank_math_seo_score", true);
    $score=is_numeric($scoreRaw) ? (int) round((float) $scoreRaw) : null;
    $robotsRaw=get_post_meta($post->ID, "rank_math_robots", true);
    $robots=is_array($robotsRaw) ? implode(",", array_map("strval", $robotsRaw)) : (string) $robotsRaw;
    $storedSeoTitle=$decode((string) get_post_meta($post->ID, "rank_math_title", true));
    $storedSeoDescription=$decode((string) get_post_meta($post->ID, "rank_math_description", true));
    $effectiveSeoTitle=$storedSeoTitle;
    $effectiveSeoDescription=$storedSeoDescription;
    $effectiveSeoSource=$storedSeoTitle !== "" || $storedSeoDescription !== "" ? "rank_math_post_meta" : "";
    if ($fetchEffectiveFrontend && $effectiveFetchCount < $effectiveFrontendLimit && (string)$post->post_status === "publish" && ($storedSeoTitle === "" || $storedSeoDescription === "")) {
        $permalink=(string) get_permalink($post);
        if ($permalink !== "") {
            $response=wp_remote_get($permalink, ["timeout"=>$effectiveFrontendTimeout, "redirection"=>3, "sslverify"=>false, "headers"=>["Cache-Control"=>"no-cache"]]);
            $effectiveFetchCount++;
            if (!is_wp_error($response)) {
                $html=(string) wp_remote_retrieve_body($response);
                $htmlTitle=$extractTitle($html);
                $htmlDescription=$extractMetaContent($html, "description");
                if ($storedSeoTitle === "" && $htmlTitle !== "") $effectiveSeoTitle=$htmlTitle;
                if ($storedSeoDescription === "" && $htmlDescription !== "") $effectiveSeoDescription=$htmlDescription;
                if ($htmlTitle !== "" || $htmlDescription !== "") $effectiveSeoSource="frontend_html";
            }
        }
    }
    $pages[]=[
        "id"=>(int) $post->ID,
        "post_type"=>(string) $post->post_type,
        "status"=>(string) $post->post_status,
        "title"=>$decode(get_the_title($post)),
        "slug"=>(string) $post->post_name,
        "url"=>get_permalink($post),
        "edit_url"=>get_edit_post_link($post->ID, ""),
        "parent_id"=>(int) $post->post_parent,
        "depth"=>(int) $depth,
        "path"=>$path,
        "menu_order"=>(int) $post->menu_order,
        "template"=>(string) get_page_template_slug($post),
        "critical"=>$critical,
        "is_critical"=>$critical,
        "critical_key"=>$criticalKey,
        "critical_label"=>$criticalLabel,
        "modified_gmt"=>(string) $post->post_modified_gmt,
        "featured_image_id"=>$thumbId,
        "featured_image"=>$thumbUrl,
        "featured_image_url"=>$thumbUrl,
        "featured_image_alt"=>$thumbAlt,
        "featured_image_title"=>$thumbPost ? $decode((string) $thumbPost->post_title) : "",
        "featured_image_caption"=>$thumbPost ? $decode((string) $thumbPost->post_excerpt) : "",
        "featured_image_description"=>$thumbPost ? $decode((string) $thumbPost->post_content) : "",
        "featured_image_file"=>$thumbFile,
        "featured_image_file_name"=>$thumbFile !== "" ? basename($thumbFile) : "",
        "featured_image_mime"=>$thumbPost ? (string) $thumbPost->post_mime_type : "",
        "excerpt"=>$decode((string) $post->post_excerpt),
        "content_text"=>$contentText,
        "content_html"=>$contentHtml,
        "outbound_internal_links"=>$internalLinks,
        "seo_title"=>$storedSeoTitle,
        "seo_description"=>$storedSeoDescription,
        "effective_seo_title"=>$effectiveSeoTitle,
        "effective_seo_description"=>$effectiveSeoDescription,
        "seo_title_source"=>$storedSeoTitle !== "" ? "rank_math_title" : ($effectiveSeoTitle !== "" ? "frontend_html" : "empty"),
        "seo_description_source"=>$storedSeoDescription !== "" ? "rank_math_description" : ($effectiveSeoDescription !== "" ? "frontend_html" : "empty"),
        "effective_seo_source"=>$effectiveSeoSource,
        "seo_score"=>$score,
        "seo_score_raw"=>(string) $scoreRaw,
        "seo_score_source"=>$score === null ? "" : "rank_math_seo_score",
        "focus_keyword"=>$decode((string) get_post_meta($post->ID, "rank_math_focus_keyword", true)),
        "rank_math_focus_keyword"=>$decode((string) get_post_meta($post->ID, "rank_math_focus_keyword", true)),
        "canonical_url"=>$decode((string) get_post_meta($post->ID, "rank_math_canonical_url", true)),
        "robots"=>$decode($robots),
    ];
}
echo "HEXA_RANKMATH_INVENTORY:" . wp_json_encode(["success"=>true,"message"=>count($pages) . " page(s) loaded.","pages"=>$pages]);
PHP
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
        $updates = [];
        if (array_key_exists("seo_title", $payload)) {
            $updates["rank_math_title"] = trim((string) ($payload["seo_title"] ?? ""));
        }
        if (array_key_exists("seo_description", $payload)) {
            $updates["rank_math_description"] = trim((string) ($payload["seo_description"] ?? ""));
        }
        if (array_key_exists("focus_keyword", $payload)) {
            $updates["rank_math_focus_keyword"] = trim((string) ($payload["focus_keyword"] ?? ""));
        }
        if (array_key_exists("canonical_url", $payload)) {
            $updates["rank_math_canonical_url"] = trim((string) ($payload["canonical_url"] ?? ""));
        }
        if ($updates === []) {
            return ["success" => false, "message" => "No Rank Math fields were provided."];
        }

        $php = implode("", [
            "\$pageId=" . var_export($pageId, true) . ";",
            "\$updates=" . var_export($updates, true) . ";",
            <<<'PHP'
$decode=function($value){ return html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, get_bloginfo("charset") ?: "UTF-8"); };
$post=get_post($pageId);
if (!$post) {
    echo "HEXA_RANKMATH_WRITE:" . wp_json_encode(["success"=>false,"message"=>"Page not found."]);
    return;
}
foreach ($updates as $metaKey=>$metaValue) {
    update_post_meta($pageId, (string) $metaKey, (string) $metaValue);
}
clean_post_cache($pageId);
echo "HEXA_RANKMATH_WRITE:" . wp_json_encode([
    "success"=>true,
    "message"=>"Rank Math SEO fields updated.",
    "page"=>[
        "id"=>$pageId,
        "url"=>get_permalink($pageId),
        "seo_title"=>$decode((string) get_post_meta($pageId, "rank_math_title", true)),
        "seo_description"=>$decode((string) get_post_meta($pageId, "rank_math_description", true)),
        "focus_keyword"=>$decode((string) get_post_meta($pageId, "rank_math_focus_keyword", true)),
        "rank_math_focus_keyword"=>$decode((string) get_post_meta($pageId, "rank_math_focus_keyword", true)),
        "canonical_url"=>$decode((string) get_post_meta($pageId, "rank_math_canonical_url", true)),
        "seo_score"=>is_numeric(get_post_meta($pageId, "rank_math_seo_score", true)) ? (int) round((float) get_post_meta($pageId, "rank_math_seo_score", true)) : null,
        "seo_score_source"=>is_numeric(get_post_meta($pageId, "rank_math_seo_score", true)) ? "rank_math_seo_score" : "",
    ],
]);
PHP
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
        foreach ([
            "title",
            "excerpt",
            "content_text",
            "content_html",
            "seo_title",
            "seo_description",
            "effective_seo_title",
            "effective_seo_description",
            "seo_title_source",
            "seo_description_source",
            "effective_seo_source",
            "featured_image",
            "featured_image_url",
            "featured_image_alt",
            "featured_image_title",
            "featured_image_caption",
            "featured_image_description",
            "featured_image_file_name",
            "critical_label",
            "critical_key",
            "path",
            "slug",
            "focus_keyword",
            "rank_math_focus_keyword",
            "canonical_url",
            "robots",
            "seo_score_raw",
            "seo_score_source",
        ] as $field) {
            if (array_key_exists($field, $page)) {
                $page[$field] = $this->decodeText((string) ($page[$field] ?? ""));
            }
        }

        if (array_key_exists("seo_score", $page)) {
            $page["seo_score"] = is_numeric($page["seo_score"]) ? (int) $page["seo_score"] : null;
        }
        if (isset($page["outbound_internal_links"]) && is_array($page["outbound_internal_links"])) {
            $page["outbound_internal_links"] = array_values(array_filter(array_map(fn ($url) => $this->decodeText((string) $url), $page["outbound_internal_links"])));
        } else {
            $page["outbound_internal_links"] = [];
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
