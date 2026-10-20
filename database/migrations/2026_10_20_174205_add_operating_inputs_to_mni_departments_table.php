<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Monthly MNI: the department's Communication Allowance and Miscellaneous expenses, typed in and split evenly across the CRAs.
     */
    public function up(): void
    {
        Schema::table('mni_departments', function (Blueprint $table) {
            $table->decimal('communication_allowance', 14, 2)->nullable()->after('advertising_cost');
            $table->decimal('miscellaneous_expenses', 14, 2)->nullable()->after('communication_allowance');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mni_departments', function (Blueprint $table) {
            $table->dropColumn(['communication_allowance', 'miscellaneous_expenses']);
        });
    }
};
