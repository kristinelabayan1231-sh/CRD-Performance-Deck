<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The CRA's staff account name in Pancake, used to pick out their chat
     * engagements and POS orders for Segmentation Productivity.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pancake_name')->nullable()->after('display_name');
        });

        // Starting accounts for the current CRAs, matched on their first name.
        $defaults = [
            'joanna' => 'CRD ANNA PACLIBARE 2',
            'regina' => 'CRD Rej Vergara',
            'ma. regina' => 'CRD Rej Vergara',
            'july ann' => 'CRD JULY ANN',
            'lhea' => 'CRD Lhei',
            'rose-an' => 'CRD Rose-An Orbaneja',
        ];

        $craId = DB::table('roles')->where('slug', 'cra')->value('id');

        DB::table('users')->where('role_id', $craId)->whereNull('pancake_name')->get()->each(function ($user) use ($defaults) {
            $name = strtolower(trim($user->display_name ?: (string) $user->name));

            foreach ($defaults as $prefix => $account) {
                if (str_starts_with($name, $prefix)) {
                    DB::table('users')->where('id', $user->id)->update(['pancake_name' => $account]);

                    return;
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('pancake_name');
        });
    }
};
