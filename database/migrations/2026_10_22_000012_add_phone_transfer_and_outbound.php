<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            // Live transfer to a person (E.164), only works on phone calls.
            $table->string('transfer_number', 20)->nullable();
            // Vapi phone number assigned by the super admin: inbound calls ring this bot, outbound calls come from it.
            $table->string('vapi_phone_number_id')->nullable();
            $table->string('phone_number', 20)->nullable();
            // Reminder call this many hours before a booked appointment (null = off).
            $table->unsignedSmallInteger('reminder_hours')->nullable();
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('reminder_called_at')->nullable();
            $table->timestamp('ai_callback_at')->nullable();
        });

        Schema::table('calls', function (Blueprint $table) {
            $table->string('direction', 10)->default('web'); // web | inbound | outbound
            $table->string('customer_number', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('calls', fn (Blueprint $t) => $t->dropColumn(['direction', 'customer_number']));
        Schema::table('bookings', fn (Blueprint $t) => $t->dropColumn(['reminder_called_at', 'ai_callback_at']));
        Schema::table('bots', fn (Blueprint $t) => $t->dropColumn(['transfer_number', 'vapi_phone_number_id', 'phone_number', 'reminder_hours']));
    }
};
