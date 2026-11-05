<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The order every module lists the CRAs in (by display name); anyone else comes after, oldest first. */
    private const ORDER = ['Regina', 'July Ann', 'Joanna Rose', 'Lhea', 'Rose-An'];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('cra_order')->nullable()->after('display_name');
        });

        foreach (self::ORDER as $position => $name) {
            DB::table('users')->where('display_name', $name)->update(['cra_order' => $position + 1]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('cra_order');
        });
    }
};
