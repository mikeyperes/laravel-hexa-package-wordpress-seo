<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wordpress_seo_activity_logs')) {
            return;
        }

        Schema::create('wordpress_seo_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seo_scan_id')->nullable()->constrained('wordpress_seo_scans')->nullOnDelete();
            $table->foreignId('seo_scan_target_id')->nullable()->constrained('wordpress_seo_scan_targets')->nullOnDelete();
            $table->foreignId('seo_page_record_id')->nullable()->constrained('wordpress_seo_page_records')->nullOnDelete();
            $table->foreignId('seo_execution_run_id')->nullable()->constrained('wordpress_seo_execution_runs')->nullOnDelete();
            $table->string('level', 20)->default('info')->index();
            $table->string('event', 120)->index();
            $table->text('message')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wordpress_seo_activity_logs');
    }
};
