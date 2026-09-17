<?php

use hexa_package_wordpress_seo\SeoProviders\RankMathSeoProvider;

return [
    "default_provider" => "rankmath",
    "supported_features" => [
        "seo_title",
        "seo_description",
        "featured_image",
        "seo_score",
        "focus_keyword",
        "internal_links",
        "site_indexability",
    ],
    "providers" => [
        "rankmath" => RankMathSeoProvider::class,
    ],
    "inventory" => [
        "post_types" => ["page", "post", "book", "organization"],
        "statuses" => ["publish", "draft", "future", "private", "pending"],
        "per_page" => 500,
        "fetch_effective_frontend" => true,
        "effective_frontend_limit" => 80,
        "effective_frontend_timeout" => 4,
    ],
    "proposals" => [
        "fields" => [
            "title" => "WordPress title",
            "slug" => "WordPress slug",
            "seo_title" => "Rank Math SEO title",
            "seo_description" => "Rank Math SEO description",
            "image_url" => "Featured image URL",
            "featured_image_alt" => "Featured image alt text",
            "featured_image_title" => "Featured image title",
            "featured_image_description" => "Featured image description",
            "featured_image_caption" => "Featured image caption",
            "featured_image_file_name" => "Featured image file name",
        ],
    ],
    "score" => [
        "good_at" => 80,
        "great_at" => 90,
    ],
    "internal_links" => [
        "max_suggestions" => 8,
    ],
    "url_context" => [
        "max_urls" => 5,
    ],
    "indexability" => [
        "timeout" => 12,
        "connect_timeout" => 5,
        "max_redirects" => 8,
        "concurrency" => 8,
        "max_body_bytes" => 10 * 1024 * 1024,
        "max_robots_body_bytes" => 1024 * 1024,
        "max_sitemap_body_bytes" => 25 * 1024 * 1024,
        "max_sitemap_documents" => 250,
        "max_sitemap_depth" => 8,
        "max_sitemap_urls" => 50000,
        "allow_cross_host_sitemaps" => false,
        "sitemap_urls" => [],
        "important_urls" => [],
        "http" => [],
    ],
];
