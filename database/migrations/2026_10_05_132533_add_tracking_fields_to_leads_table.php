<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('repeat_purchase')->nullable()->after('status_updated_at');
            $table->string('customer_tag')->nullable()->after('repeat_purchase');
            $table->date('contact_date')->nullable()->after('customer_tag');
            $table->string('contact_time')->nullable()->after('contact_date');
            $table->string('feedback')->nullable()->after('contact_time');
            $table->date('callback_date')->nullable()->after('feedback');
            // Reserved for the upcoming Call Recording Link column.
            $table->string('call_recording_url', 2048)->nullable()->after('callback_date');
            $table->text('notes')->nullable()->after('call_recording_url');
            $table->foreignId('notes_updated_by')->nullable()->after('notes')->constrained('users')->nullOnDelete();
            $table->timestamp('notes_updated_at')->nullable()->after('notes_updated_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('notes_updated_by');
            $table->dropColumn([
                'repeat_purchase', 'customer_tag', 'contact_date', 'contact_time', 'feedback',
                'callback_date', 'call_recording_url', 'notes', 'notes_updated_at',
            ]);
        });
    }
};
