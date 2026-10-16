<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('password');
            $table->string('avatar_path')->nullable()->after('is_admin');
            $table->string('timezone')->default('Asia/Karachi')->after('avatar_path');
            // Wallet balance in USD. Only ever changed through App\Services\WalletService.
            $table->decimal('balance', 12, 4)->default(0)->after('timezone');
            $table->timestamp('terms_accepted_at')->nullable()->after('balance');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_admin', 'avatar_path', 'timezone', 'balance', 'terms_accepted_at']);
        });
    }
};
