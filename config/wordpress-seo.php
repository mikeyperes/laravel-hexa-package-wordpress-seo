<?php

return [
    "inventory" => [
        "post_types" => ["page", "post", "book", "organization"],
        // The SEO pass covers what visitors and Google can see: published only.
        "statuses" => ["publish"],
        "per_page" => 200,
        "max_batches" => 200,
        // Published pages whose served HTML is read (title, description,
        // headings). Throttled like every scanner request; raise per run only.
        "served_limit" => 100,
    ],
    "internal_links" => [
        "max_suggestions" => 8,
    ],
    "llms_txt" => [
        // Links Rank Math lists per content type in llms.txt.
        "limit" => 100,
        // Site names that mean Rank Math's site name was never filled in.
        "placeholder_site_names" => ["admin", "administrator", "wordpress", "my wordpress site", "my blog", "site title"],
    ],
    "robots_txt" => [
        // Rules every WordPress site gets, for every crawler.
        "base_rules" => [
            "Disallow: /wp-admin/",
            "Allow: /wp-admin/admin-ajax.php",
            "Disallow: /?s=",
            "Disallow: /search/",
        ],
        // AI search and assistant crawlers, named so they are allowed on
        // purpose and follow the same rules as every other crawler.
        "ai_crawlers" => [
            "GPTBot",
            "OAI-SearchBot",
            "ChatGPT-User",
            "ClaudeBot",
            "Claude-SearchBot",
            "Claude-User",
            "Google-Extended",
            "PerplexityBot",
            "Perplexity-User",
            "Applebot-Extended",
            "CCBot",
        ],
        // The homepage is fetched as each of these to catch a firewall block.
        "ai_fetch_agents" => [
            "GPTBot" => "Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.2; +https://openai.com/gptbot",
            "OAI-SearchBot" => "Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; OAI-SearchBot/1.0; +https://openai.com/searchbot",
            "ClaudeBot" => "Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)",
            "PerplexityBot" => "Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)",
            "CCBot" => "CCBot/2.0 (https://commoncrawl.org/faq/)",
        ],
    ],
    "indexability" => [
        "timeout" => 12,
        "connect_timeout" => 5,
        "max_redirects" => 8,
        // CRITICAL — see BUGLOG.md SEO-BUG-001: at most 2 at a time, 1 s apart,
        // and cached pages are allowed (no cache bypass).
        "concurrency" => 2,
        "delay_ms" => 1000,
        // Sitemap URLs outside the page list fetched per scan (evenly spread).
        "sitemap_audit_sample" => 100,
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
