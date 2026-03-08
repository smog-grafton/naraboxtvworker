<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('callback_logs')) {
            return;
        }

        Schema::create('callback_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('processing_request_id')->constrained('processing_requests')->cascadeOnDelete();
            $table->string('direction', 16)->default('outbound')->index();
            $table->string('target', 32)->index();
            $table->string('url', 2048)->nullable();
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->text('response_body')->nullable();
            $table->string('error_message', 1024)->nullable();
            $table->timestamps();

            $table->index(['processing_request_id', 'target']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('callback_logs');
    }
};
