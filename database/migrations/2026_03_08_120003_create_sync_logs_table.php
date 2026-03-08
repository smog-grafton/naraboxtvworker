<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sync_logs')) {
            return;
        }

        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('processing_request_id')->constrained('processing_requests')->cascadeOnDelete();
            $table->string('target', 32)->default('portal')->index();
            $table->string('action', 64)->nullable();
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->text('response_body')->nullable();
            $table->string('error_message', 1024)->nullable();
            $table->timestamps();

            $table->index(['processing_request_id', 'target']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
    }
};
