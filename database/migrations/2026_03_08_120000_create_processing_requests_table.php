<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('processing_requests')) {
            return;
        }

        Schema::create('processing_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('external_id')->unique();
            $table->string('cdn_asset_id', 64)->nullable()->index();
            $table->unsignedBigInteger('cdn_source_id')->nullable()->index();
            $table->string('source_url', 2048)->nullable();
            $table->string('original_filename', 512)->nullable();
            $table->string('status', 32)->default('received')->index();
            $table->string('failure_reason', 1024)->nullable();
            $table->json('payload')->nullable();
            $table->json('artifact_paths')->nullable();
            $table->string('callback_url', 2048)->nullable();
            $table->string('portal_sync_hint', 512)->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processing_requests');
    }
};
