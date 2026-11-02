<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-minute wallet → monthly plans (Basic: 1 store / 1 user, Business: 5 stores / 5 users).
 * - users: the active plan and until when it is paid, plus the store the user is working on.
 * - bots: an account can now own several bots (one per store), so user_id is no longer unique.
 * - top_ups: a payment now buys a plan for some months.
 * The old wallet columns/table stay in the database untouched (history), nothing reads them any more.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('plan', 20)->nullable()->after('balance');
            $table->timestamp('plan_expires_at')->nullable()->after('plan');
            $table->unsignedBigInteger('current_bot_id')->nullable()->after('plan_expires_at');
        });

        Schema::table('bots', function (Blueprint $table) {
            // MySQL needs another index on user_id for the foreign key before the unique one can go.
            $table->index('user_id', 'bots_user_id_index');
        });
        Schema::table('bots', function (Blueprint $table) {
            $table->dropUnique('bots_user_id_unique');
        });

        Schema::table('top_ups', function (Blueprint $table) {
            $table->string('plan', 20)->nullable()->after('user_id');
            $table->unsignedTinyInteger('months')->default(1)->after('plan');
            $table->decimal('amount_usd', 10, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('top_ups', function (Blueprint $table) {
            $table->dropColumn(['plan', 'months']);
        });
        Schema::table('bots', function (Blueprint $table) {
            $table->unique('user_id', 'bots_user_id_unique');
        });
        Schema::table('bots', function (Blueprint $table) {
            $table->dropIndex('bots_user_id_index');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['plan', 'plan_expires_at', 'current_bot_id']);
        });
    }
};
