<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Exclusive start dates are lead days, like Settings → Working Date. The Nov 1, 2026 transfer was saved as the real
     * date, whose lead day is Oct 2. Any other start date was picked in Settings and is left alone.
     */
    public function up(): void
    {
        DB::table('users')->whereDate('exclusive_from', '2026-11-01')->update(['exclusive_from' => '2026-10-02']);
    }

    /**
     * Left in place: the column goes with the migration that created it.
     */
    public function down(): void {}
};
