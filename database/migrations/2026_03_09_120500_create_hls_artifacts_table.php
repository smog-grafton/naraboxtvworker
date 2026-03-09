<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hls_artifacts')) {
            return;
        }

        Schema::create('hls_artifacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('processing_request_id')->index();
            $table->uuid('external_id')->index();
            $table->string('cdn_asset_id', 64)->nullable()->index();
            $table->unsignedBigInteger('cdn_source_id')->nullable()->index();
            $table->string('status', 32)->default('packaging')->index();
            $table->string('quality_status', 32)->nullable();
            $table->json('qualities_json')->nullable();
            $table->string('hls_dir', 1024)->nullable();
            $table->string('zip_path', 1024)->nullable();
            $table->unsignedBigInteger('zip_size_bytes')->nullable();
            $table->string('download_token', 128)->unique()->nullable();
            $table->timestamp('download_expires_at')->nullable();
            $table->timestamp('last_fetched_at')->nullable();
            $table->string('failure_reason', 1024)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hls_artifacts');
    }
};

