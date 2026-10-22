<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One connected Google Calendar per owner. Tokens are stored encrypted (model casts).
        Schema::create('google_calendars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('google_email')->nullable();
            $table->string('calendar_id')->default('primary');
            $table->text('refresh_token');
            $table->text('access_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('last_error', 400)->nullable();
            $table->timestamps();
        });

        Schema::table('bots', function (Blueprint $table) {
            // Live booking: during the call the bot checks free slots and books them itself.
            $table->boolean('live_booking')->default(false);
            $table->unsignedSmallInteger('slot_minutes')->default(30);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            // Set when the bot booked during the call (tool call), so the end-of-call report doesn't create a duplicate.
            $table->string('vapi_call_id')->nullable()->index();
            $table->string('google_event_id')->nullable();
            $table->index(['bot_id', 'starts_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']));
        Schema::table('bookings', function (Blueprint $t) {
            $t->dropIndex(['bot_id', 'starts_at']);
            $t->dropIndex(['vapi_call_id']);
            $t->dropColumn(['starts_at', 'ends_at', 'vapi_call_id', 'google_event_id']);
        });
        Schema::table('bots', fn (Blueprint $t) => $t->dropColumn(['live_booking', 'slot_minutes']));
        Schema::dropIfExists('google_calendars');
    }
};
