<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The SEO dashboard (stored scans, page records, proposals, execution runs and
 * their logs) was removed in 0.4.0. Skills read and write live through the
 * wordpress-seo:site command, so nothing is stored any more.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'wordpress_seo_indexability_url_records',
            'wordpress_seo_activity_logs',
            'wordpress_seo_execution_runs',
            'wordpress_seo_proposals',
            'wordpress_seo_page_records',
            'wordpress_seo_scan_targets',
            'wordpress_seo_scans',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function down(): void
    {
        // Intentionally empty: the removed dashboard is not restored.
    }
};
