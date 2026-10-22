<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Owner notification preferences
            $table->boolean('notify_email')->default(true);
            $table->boolean('notify_whatsapp')->default(false);
            $table->string('whatsapp_number', 20)->nullable();
            $table->boolean('notify_daily_summary')->default(true);
            // Spending cap per calendar month (USD, same unit as the wallet). Null = no cap.
            $table->decimal('monthly_cap', 10, 2)->nullable();
        });

        Schema::table('bots', function (Blueprint $table) {
            // ["example.com", "shop.example.com"]; empty = any site may embed the widget
            $table->json('allowed_domains')->nullable();
            // {"mon": {"open": "10:00", "close": "21:00"}, "fri": null (closed), ...}
            $table->json('business_hours')->nullable();
            // [{"date": "2026-12-25", "note": "Quaid Day"}]
            $table->json('holidays')->nullable();
        });

        Schema::table('calls', function (Blueprint $table) {
            // Questions the bot could not answer from its facts (knowledge-gap report)
            $table->json('unanswered')->nullable();
            $table->boolean('after_hours')->default(false);
        });

        // One row per notification actually sent; dedupe_key makes "send once" safe across retries.
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 40);       // lead | low_balance | daily_summary | integration_failed | cap_reached
            $table->string('channel', 20);    // email | whatsapp
            $table->string('dedupe_key', 191);
            $table->string('status', 20);     // sent | failed | skipped
            $table->string('error', 400)->nullable();
            $table->timestamps();
            $table->unique(['dedupe_key', 'channel']);
            $table->index(['user_id', 'created_at']);
        });

        // Accounts that exist before email verification was introduced count as verified.
        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
        Schema::table('calls', fn (Blueprint $t) => $t->dropColumn(['unanswered', 'after_hours']));
        Schema::table('bots', fn (Blueprint $t) => $t->dropColumn(['allowed_domains', 'business_hours', 'holidays']));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['notify_email', 'notify_whatsapp', 'whatsapp_number', 'notify_daily_summary', 'monthly_cap']));
    }
};
