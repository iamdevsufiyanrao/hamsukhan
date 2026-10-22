<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            // Last Vapi sync failure in plain words (null = in sync). Shown in Bot Studio with a retry button.
            $table->string('sync_error', 400)->nullable()->after('synced_at');
        });

        Schema::table('bookings', function (Blueprint $table) {
            // "widget" = callback form on the website, "call" = captured by the bot during a call.
            $table->string('source', 20)->default('call')->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('bots', fn (Blueprint $table) => $table->dropColumn('sync_error'));
        Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn('source'));
    }
};
