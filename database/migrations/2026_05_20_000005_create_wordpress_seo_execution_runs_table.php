<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wordpress_seo_execution_runs')) {
            return;
        }

        Schema::create('wordpress_seo_execution_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seo_scan_id')->nullable()->constrained('wordpress_seo_scans')->nullOnDelete();
            $table->foreignId('seo_page_record_id')->nullable()->constrained('wordpress_seo_page_records')->nullOnDelete();
            $table->foreignId('seo_proposal_id')->nullable()->constrained('wordpress_seo_proposals')->nullOnDelete();
            $table->string('action', 80)->index();
            $table->json('request_payload')->nullable();
            $table->json('result_payload')->nullable();
            $table->string('status', 50)->default('queued')->index();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wordpress_seo_execution_runs');
    }
};
