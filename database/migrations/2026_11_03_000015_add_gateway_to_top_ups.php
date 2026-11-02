<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Plan payments made online (JazzCash checkout) next to the manual ones: which gateway, and what it answered. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('top_ups', function (Blueprint $table) {
            $table->string('gateway', 20)->nullable()->after('method'); // null = manual payment with a screenshot
            $table->json('gateway_response')->nullable()->after('admin_note');
        });
    }

    public function down(): void
    {
        Schema::table('top_ups', function (Blueprint $table) {
            $table->dropColumn(['gateway', 'gateway_response']);
        });
    }
};
