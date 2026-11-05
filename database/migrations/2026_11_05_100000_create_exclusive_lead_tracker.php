<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Exclusive Lead Tracker: when a CRA left the Segmentation Tracker, each day's list, and who may use it.
     *
     * @var array<string, list<string>>
     */
    private array $grants = [
        'cra' => ['exclusive_leads.view'],
        'cra-supervisor' => ['exclusive_leads.view', 'exclusive_leads.view_all', 'exclusive_leads.manage'],
    ];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('exclusive_from')->nullable();
        });

        Schema::create('exclusive_list_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('list_date');
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('source', 20);
            $table->timestamps();
            $table->unique(['user_id', 'list_date', 'lead_id']);
            $table->index('lead_id');
        });

        foreach ($this->grants as $slug => $permissions) {
            $role = DB::table('roles')->where('slug', $slug)->first();

            if ($role) {
                $current = json_decode($role->permissions ?? '[]', true) ?: [];
                DB::table('roles')->where('id', $role->id)->update([
                    'permissions' => json_encode(array_values(array_unique([...$current, ...$permissions]))),
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->grants as $slug => $permissions) {
            $role = DB::table('roles')->where('slug', $slug)->first();

            if ($role) {
                $current = json_decode($role->permissions ?? '[]', true) ?: [];
                DB::table('roles')->where('id', $role->id)->update([
                    'permissions' => json_encode(array_values(array_diff($current, $permissions))),
                ]);
            }
        }

        Schema::dropIfExists('exclusive_list_leads');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('exclusive_from');
        });
    }
};
