<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Sample voices shown on the landing page. Uploaded by the super admin, stored privately, listen-only. */
    public function up(): void
    {
        Schema::create('voice_samples', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('label', 120)->nullable(); // e.g. "Warm · female · salon greeting"
            $table->string('language', 30)->default('English');
            $table->string('path');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_samples');
    }
};
