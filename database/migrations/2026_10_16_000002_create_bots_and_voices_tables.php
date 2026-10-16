<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('provider')->default('elevenlabs');
            $table->string('provider_voice_id')->nullable();
            $table->string('sample_path')->nullable();
            $table->unsignedInteger('sample_seconds')->default(0);
            $table->string('status')->default('ready'); // processing | ready | failed
            $table->timestamp('consent_at');
            $table->timestamps();
        });

        Schema::create('bots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->uuid('public_key')->unique();
            $table->string('business_name')->nullable();
            $table->string('website')->nullable();
            $table->text('greeting');
            $table->string('end_message')->nullable();
            $table->string('language')->default('English');
            $table->text('prompt');
            $table->text('quick_facts')->nullable();
            $table->unsignedSmallInteger('max_call_minutes')->default(10);
            $table->foreignId('voice_id')->nullable()->constrained('voices')->nullOnDelete();
            $table->string('preset_voice')->nullable();
            $table->json('widget')->nullable();
            $table->string('vapi_assistant_id')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('installed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('knowledge_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('path');
            $table->unsignedBigInteger('size_bytes');
            $table->string('mime')->nullable();
            $table->string('provider_file_id')->nullable();
            $table->string('status')->default('indexed'); // processing | indexed | failed
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_files');
        Schema::dropIfExists('bots');
        Schema::dropIfExists('voices');
    }
};
