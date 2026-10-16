<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable()->after('balance');
            $table->string('suspended_reason')->nullable()->after('suspended_at');
        });

        // What the bot must collect on a call (e.g. items + address for a restaurant). Sent to Vapi as structured-data schema.
        Schema::table('bots', function (Blueprint $table) {
            $table->json('capture_fields')->nullable()->after('quick_facts');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->string('type')->default('booking')->after('call_id'); // booking | order | callback
            $table->json('details')->nullable()->after('preferred_time'); // values of the custom capture fields
        });

        // A destination in the customer's own software (their webhook URL or HTML form endpoint).
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('kind'); // webhook (signed JSON) | form (mapped form fields)
            $table->string('url', 500);
            $table->json('events'); // ['booking.created', 'order.created']
            $table->json('field_map')->nullable(); // [{target, source, value?}]
            $table->text('secret'); // encrypted; used to sign deliveries
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_delivery_at')->nullable();
            $table->string('last_status')->nullable(); // delivered | failed
            $table->timestamps();
        });

        Schema::create('integration_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event');
            $table->boolean('is_test')->default(false);
            $table->json('payload');
            $table->string('status')->default('pending'); // pending | delivered | failed
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->text('response_body')->nullable();
            $table->string('external_reference')->nullable(); // id the customer's system returned (e.g. ORD-123)
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_attempt_at']);
        });

        // Everything a super admin does to someone else's account.
        Schema::create('admin_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action'); // impersonate | suspend | activate | adjust_balance | approve_topup | reject_topup
            $table->json('meta')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['target_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_actions');
        Schema::dropIfExists('integration_deliveries');
        Schema::dropIfExists('integrations');
        Schema::table('bookings', fn (Blueprint $t) => $t->dropColumn(['type', 'details']));
        Schema::table('bots', fn (Blueprint $t) => $t->dropColumn('capture_fields'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['suspended_at', 'suspended_reason']));
    }
};
