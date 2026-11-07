<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Exclusive Lead Tracker's limits, by the real date they start, and who may set them. Team Leads also need the
     * page itself to reach its Settings.
     *
     * @var array<string, list<string>>
     */
    private array $grants = [
        'cra-supervisor' => ['exclusive_leads.limits'],
        'cra-team-lead' => ['exclusive_leads.view', 'exclusive_leads.view_all', 'exclusive_leads.limits'],
    ];

    public function up(): void
    {
        Schema::create('exclusive_lead_limits', function (Blueprint $table) {
            $table->id();
            $table->date('effective_from')->unique();
            $table->unsignedInteger('daily_leads');
            $table->unsignedInteger('month_target');
            $table->unsignedInteger('orders_per_day');
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
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
        Schema::dropIfExists('exclusive_lead_limits');

        foreach (DB::table('roles')->whereIn('slug', array_keys($this->grants))->get() as $role) {
            $current = json_decode($role->permissions ?? '[]', true) ?: [];
            DB::table('roles')->where('id', $role->id)->update([
                'permissions' => json_encode(array_values(array_diff($current, ['exclusive_leads.limits']))),
            ]);
        }
    }
};
