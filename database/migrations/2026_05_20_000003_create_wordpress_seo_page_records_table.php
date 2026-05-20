<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wordpress_seo_page_records')) {
            return;
        }

        Schema::create('wordpress_seo_page_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seo_scan_target_id')->constrained('wordpress_seo_scan_targets')->cascadeOnDelete();
            $table->unsignedBigInteger('external_page_id')->nullable()->index();
            $table->string('page_type', 50)->nullable()->index();
            $table->string('page_status', 50)->nullable()->index();
            $table->text('title')->nullable();
            $table->string('slug')->nullable()->index();
            $table->text('permalink')->nullable();
            $table->string('modified_gmt', 40)->nullable()->index();
            $table->json('current_payload')->nullable();
            $table->json('extracted_context')->nullable();
            $table->string('review_state', 50)->default('pending')->index();
            $table->string('inventory_hash', 64)->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['seo_scan_target_id', 'external_page_id'], 'wp_seo_page_records_target_page_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wordpress_seo_page_records');
    }
};
