<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Shopify stores connected to a bot through the Hamsukhan Shopify app (OAuth). One shop belongs to one bot. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_shops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->string('shop')->unique(); // x.myshopify.com
            $table->text('access_token'); // encrypted
            $table->string('scopes')->nullable();
            $table->string('script_tag_id')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('uninstalled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_shops');
    }
};
