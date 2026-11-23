<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each CRA keeps her own SMS templates (an edit no longer changes everyone's) and her own name in texts, the code
     * name {cra_name} becomes. The shared templates are cleared, as asked on 2026-11-23: the CRAs start over with their
     * own spiels. Sent reminders keep their text; their template link is emptied by its foreign key.
     */
    public function up(): void
    {
        DB::table('sms_templates')->delete();

        Schema::table('sms_templates', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('sms_name', 60)->nullable()->after('display_name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('sms_name');
        });

        Schema::table('sms_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
