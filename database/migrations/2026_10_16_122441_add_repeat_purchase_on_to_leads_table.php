<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The day a lead's Repeat Purchase was set to Yes: the dashboard's Retained and Repeat tiles count it
     * on that day, not on its lead day. Leads already marked Yes get their date of contact, else the day
     * their status was last set, else the day they were last saved.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->date('repeat_purchase_on')->nullable()->after('repeat_purchase')->index();
        });

        DB::table('leads')->where('repeat_purchase', 'yes')
            ->update(['repeat_purchase_on' => DB::raw('coalesce(contact_date, date(status_updated_at), date(updated_at))')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['repeat_purchase_on']);
            $table->dropColumn('repeat_purchase_on');
        });
    }
};
