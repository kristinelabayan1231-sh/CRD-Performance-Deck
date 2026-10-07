<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * "New Customer" leads are now "FSD Leads" (delivered through Facebook Sales).
     */
    public function up(): void
    {
        DB::table('leads')->where('lead_type', 'new')->update(['lead_type' => 'fsd']);
    }

    public function down(): void
    {
        DB::table('leads')->where('lead_type', 'fsd')->update(['lead_type' => 'new']);
    }
};
