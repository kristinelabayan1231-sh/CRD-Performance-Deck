<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SMS follow-ups: a text is a reminder or a follow-up (1 to 3, every 7 days after the last text while the customer
     * hasn't reordered), scheduled automatically or by the CRA (scheduled_by, note). covers keeps every product and
     * run-out a text is for, so one text can cover a customer's products running out close together and none is texted
     * twice. After the 3rd follow-up with no reorder the customer is on No response (no_response_at) until handled
     * (resolved_at); reordered_at is when the CRA marked them reordered, and no_reorder_reason why a text didn't bring a
     * reorder (typed by the CRA; else the customer's reply shows).
     */
    public function up(): void
    {
        Schema::table('restock_reminders', function (Blueprint $table) {
            $table->string('kind', 20)->default('reminder')->after('customer_type');
            $table->unsignedTinyInteger('follow_up_number')->default(0)->after('kind');
            $table->json('covers')->nullable()->after('products');
            $table->foreignId('scheduled_by')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->string('note')->nullable()->after('scheduled_by');
            $table->string('no_reorder_reason')->nullable()->after('note');
            $table->timestamp('reordered_at')->nullable()->after('sent_at');
            $table->timestamp('no_response_at')->nullable()->after('reordered_at');
            $table->timestamp('resolved_at')->nullable()->after('no_response_at');
            $table->index(['phone_key', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('restock_reminders', function (Blueprint $table) {
            $table->dropIndex(['phone_key', 'status']);
            $table->dropConstrainedForeignId('scheduled_by');
            $table->dropColumn(['kind', 'follow_up_number', 'covers', 'note', 'no_reorder_reason', 'reordered_at', 'no_response_at', 'resolved_at']);
        });
    }
};
