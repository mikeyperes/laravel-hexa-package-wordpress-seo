<?php

use hexa_package_wordpress_seo\SeoProviders\RankMathSeoProvider;

return [
    "default_provider" => "rankmath",
    "supported_features" => [
        "seo_title",
        "seo_description",
        "featured_image",
    ],
    "providers" => [
        "rankmath" => RankMathSeoProvider::class,
    ],
    "inventory" => [
        "post_types" => ["page", "post", "book", "organization"],
        "statuses" => ["publish", "draft", "future", "private", "pending"],
        "per_page" => 500,
    ],
    "url_context" => [
        "max_urls" => 5,
    ],
];
