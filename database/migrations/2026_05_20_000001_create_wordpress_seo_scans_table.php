<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wordpress_seo_scans')) {
            return;
        }

        Schema::create('wordpress_seo_scans', function (Blueprint $table) {
            $table->id();
            $table->string('scope_type', 50)->index();
            $table->json('scope_payload')->nullable();
            $table->string('provider_key', 80)->nullable()->index();
            $table->json('feature_set')->nullable();
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
        Schema::dropIfExists('wordpress_seo_scans');
    }
};
