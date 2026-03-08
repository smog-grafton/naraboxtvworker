<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('processing_attempts')) {
            return;
        }

        Schema::create('processing_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('processing_request_id')->constrained('processing_requests')->cascadeOnDelete();
            $table->string('stage', 64)->index();
            $table->string('status', 32)->default('running')->index();
            $table->text('log_output')->nullable();
            $table->string('error_message', 1024)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['processing_request_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processing_attempts');
    }
};
