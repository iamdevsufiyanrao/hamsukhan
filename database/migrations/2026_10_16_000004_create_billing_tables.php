<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Manual payments: the customer sends money via JazzCash/Easypaisa/bank and uploads proof;
        // an admin approves it, which credits the wallet.
        Schema::create('top_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount_usd', 10, 2);
            $table->decimal('amount_pkr', 12, 2);
            $table->string('method'); // jazzcash | easypaisa | bank
            $table->string('reference');
            $table->string('proof_path')->nullable();
            $table->string('status')->default('pending'); // pending | approved | rejected
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('admin_note')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        // Append-only ledger. Every balance change writes one row with the balance after it.
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // topup | call_charge | refund | adjustment
            $table->decimal('amount', 12, 4); // positive = credit, negative = debit
            $table->decimal('balance_after', 12, 4);
            $table->string('description');
            $table->foreignId('call_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('top_up_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('top_ups');
    }
};
