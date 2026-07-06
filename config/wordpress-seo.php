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
];
