<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wordpress_seo_proposals')) {
            return;
        }

        Schema::create('wordpress_seo_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seo_page_record_id')->constrained('wordpress_seo_page_records')->cascadeOnDelete();
            $table->string('provider_key', 80)->nullable()->index();
            $table->json('feature_set')->nullable();
            $table->json('before_payload')->nullable();
            $table->json('proposed_payload')->nullable();
            $table->json('review_payload')->nullable();
            $table->string('status', 50)->default('proposed')->index();
            $table->string('agent_driver', 80)->nullable()->index();
            $table->string('agent_model', 120)->nullable()->index();
            $table->json('extracted_urls')->nullable();
            $table->timestamp('proposed_at')->nullable()->index();
            $table->timestamp('reviewed_at')->nullable()->index();
            $table->timestamp('applied_at')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wordpress_seo_proposals');
    }
};
