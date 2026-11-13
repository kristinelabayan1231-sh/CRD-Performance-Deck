<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CRA Dashboard → Check who gets these leads: when a supervisor assigned (or kept) the lead, so it leaves the list.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('assignment_checked_at')->nullable();
            $table->foreignId('assignment_checked_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assignment_checked_by');
            $table->dropColumn('assignment_checked_at');
        });
    }
};
