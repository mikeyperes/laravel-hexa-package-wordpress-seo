<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wordpress_seo_scan_targets')) {
            return;
        }

        Schema::create('wordpress_seo_scan_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seo_scan_id')->constrained('wordpress_seo_scans')->cascadeOnDelete();
            $table->string('scope_type', 50)->index();
            $table->string('target_key', 191)->nullable()->index();
            $table->json('target_payload')->nullable();
            $table->string('provider_key', 80)->nullable()->index();
            $table->string('site_name')->nullable();
            $table->text('site_url')->nullable();
            $table->string('server_name')->nullable()->index();
            $table->string('cpanel_username')->nullable()->index();
            $table->json('plugin_state')->nullable();
            $table->string('status', 50)->default('queued')->index();
            $table->json('summary')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wordpress_seo_scan_targets');
    }
};
