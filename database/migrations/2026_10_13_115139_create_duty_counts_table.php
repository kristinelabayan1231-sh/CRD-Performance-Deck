<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * On duty entered by hand: how many CRAs were on duty on a day, without who. Used instead of that day's
     * log-ins. Filled for Oct 1–13, 2026, from before CRAs logged in from the Segmentation Tracker.
     */
    private array $counts = [
        '2026-10-01' => 2, '2026-10-02' => 2, '2026-10-03' => 2, '2026-10-04' => 3, '2026-10-05' => 4,
        '2026-10-06' => 3, '2026-10-07' => 3, '2026-10-08' => 5, '2026-10-09' => 5,
        '2026-10-10' => 2, '2026-10-11' => 2, '2026-10-12' => 2, '2026-10-13' => 2,
    ];

    public function up(): void
    {
        Schema::create('duty_counts', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->unsignedSmallInteger('count');
            $table->timestamps();
        });

        if (app()->runningUnitTests()) {
            return;
        }

        DB::table('duty_counts')->insert(collect($this->counts)->map(fn (int $count, string $date) => [
            'date' => $date, 'count' => $count, 'created_at' => now(), 'updated_at' => now(),
        ])->values()->all());
    }

    public function down(): void
    {
        Schema::dropIfExists('duty_counts');
    }
};
