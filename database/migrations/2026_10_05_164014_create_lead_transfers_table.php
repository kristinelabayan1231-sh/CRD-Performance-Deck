<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backlog transfers between CRAs, plus the built-in CRA Supervisor role.
     */
    public function up(): void
    {
        Schema::create('lead_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('transferred_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();
        DB::table('roles')->insertOrIgnore([
            'slug' => 'cra-supervisor',
            'name' => 'CRA Supervisor',
            'description' => 'Oversees all CRAs\' leads and transfers backlogs between CRAs.',
            'permissions' => json_encode(['segmentation.view', 'segmentation.view_all', 'segmentation.transfer']),
            'is_system' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_transfers');

        // Only remove the CRA Supervisor role if nobody holds it.
        $roleId = DB::table('roles')->where('slug', 'cra-supervisor')->value('id');
        if ($roleId && ! DB::table('users')->where('role_id', $roleId)->exists()) {
            DB::table('roles')->where('id', $roleId)->delete();
        }
    }
};
