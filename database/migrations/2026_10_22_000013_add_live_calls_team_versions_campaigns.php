<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Calls in progress right now (from Vapi status-update messages), for live listen / control.
        Schema::create('live_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->string('vapi_call_id')->unique();
            $table->string('direction', 10)->default('web');
            $table->string('customer_number', 20)->nullable();
            $table->text('listen_url')->nullable();
            $table->text('control_url')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamps();
        });

        // Snapshot of the bot's instructions and facts before every change, for history + restore.
        Schema::create('knowledge_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('greeting')->nullable();
            $table->text('prompt')->nullable();
            $table->text('quick_facts')->nullable();
            $table->string('note', 120)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['bot_id', 'created_at']);
        });

        // Team members: a member works inside the owner's account with a role.
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('owner_id')->nullable()->after('id')->constrained('users')->cascadeOnDelete();
            $table->string('team_role', 20)->nullable(); // admin | operator | viewer (null = account owner)
        });

        // Outbound campaigns: the bot calls a list of customers who agreed to be called.
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->text('purpose');
            $table->string('first_message', 300);
            $table->string('status', 12)->default('draft'); // draft | running | paused | done
            $table->unsignedSmallInteger('per_run')->default(3);
            $table->timestamp('consent_confirmed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('campaign_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80)->nullable();
            $table->string('phone', 20);
            $table->string('status', 12)->default('pending'); // pending | calling | done | failed | skipped
            $table->string('vapi_call_id')->nullable()->index();
            $table->string('error', 300)->nullable();
            $table->timestamp('called_at')->nullable();
            $table->timestamps();
            $table->unique(['campaign_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_contacts');
        Schema::dropIfExists('campaigns');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_id');
            $table->dropColumn('team_role');
        });
        Schema::dropIfExists('knowledge_versions');
        Schema::dropIfExists('live_calls');
    }
};
