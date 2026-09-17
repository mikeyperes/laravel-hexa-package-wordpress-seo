<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wordpress_seo_indexability_url_records')) {
            return;
        }

        Schema::create('wordpress_seo_indexability_url_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seo_scan_target_id')->constrained('wordpress_seo_scan_targets')->cascadeOnDelete();
            $table->string('url_hash', 64);
            $table->text('url');
            $table->text('final_url')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable()->index();
            $table->boolean('from_sitemap')->default(false)->index();
            $table->boolean('important')->default(false)->index();
            $table->boolean('robots_allowed')->nullable()->index();
            $table->boolean('canonical_self')->nullable()->index();
            $table->boolean('soft_404')->default(false)->index();
            $table->boolean('access_blocked')->default(false)->index();
            $table->boolean('indexable')->default(false)->index();
            $table->text('canonical_url')->nullable();
            $table->string('response_sha256', 64)->nullable()->index();
            $table->json('reasons')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('fetched_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['seo_scan_target_id', 'url_hash'], 'wp_seo_indexability_target_url_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wordpress_seo_indexability_url_records');
    }
};
