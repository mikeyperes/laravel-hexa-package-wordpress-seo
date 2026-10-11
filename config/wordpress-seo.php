<?php

return [
    "inventory" => [
        "post_types" => ["page", "post", "book", "organization"],
        "statuses" => ["publish", "draft", "future", "private", "pending"],
        "per_page" => 500,
        "max_batches" => 200,
        "fetch_effective_frontend" => true,
        "effective_frontend_limit" => 80,
        "effective_frontend_timeout" => 4,
    ],
    "internal_links" => [
        "max_suggestions" => 8,
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
