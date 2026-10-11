<?php

return [
    "inventory" => [
        "post_types" => ["page", "post", "book", "organization"],
        "statuses" => ["publish", "draft", "future", "private", "pending"],
        "per_page" => 200,
        "max_batches" => 200,
        // Published pages whose served HTML is read (title, description,
        // headings). Throttled like every scanner request; raise per run only.
        "served_limit" => 100,
    ],
    "internal_links" => [
        "max_suggestions" => 8,
    ],
    "indexability" => [
        "timeout" => 12,
        "connect_timeout" => 5,
        "max_redirects" => 8,
        // CRITICAL — see BUGLOG.md SEO-BUG-001: at most 2 at a time, 1 s apart,
        // and cached pages are allowed (no cache bypass).
        "concurrency" => 2,
        "delay_ms" => 1000,
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
