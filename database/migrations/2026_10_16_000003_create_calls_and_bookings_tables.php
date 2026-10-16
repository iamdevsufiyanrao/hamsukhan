<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            // Vapi's call id. Unique so a retried webhook can never create or charge a call twice.
            $table->string('vapi_call_id')->nullable()->unique();
            $table->string('title');
            $table->string('outcome_tag')->default('No outcome');
            $table->string('status')->default('completed'); // completed | dropped | failed
            $table->string('ended_reason')->nullable();
            $table->boolean('is_test')->default(false);
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->decimal('cost', 10, 4)->default(0);
            $table->string('recording_url')->nullable();
            $table->text('summary')->nullable();
            $table->timestamps();

            $table->index(['bot_id', 'started_at']);
        });

        Schema::create('call_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_id')->constrained()->cascadeOnDelete();
            $table->string('role'); // ai | user
            $table->text('text');
            $table->unsignedInteger('position');
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('call_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name');
            $table->string('phone')->nullable();
            $table->string('request');
            $table->string('preferred_time')->nullable();
            $table->string('status')->default('new'); // new | confirmed | done
            $table->timestamps();

            $table->index(['bot_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('call_messages');
        Schema::dropIfExists('calls');
    }
};
