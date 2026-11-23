<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Built without locking the table, so syncs keep writing orders while it is made.
     */
    public $withinTransaction = false;

    /**
     * An index for whereJsonContains('tags', …) (the exclusive POS tag), which otherwise reads every order: on Postgres it
     * runs as ("tags")::jsonb @> …, so the index is on that same expression. MySQL has no such index, so nothing changes there.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS pancake_orders_tags_index ON pancake_orders USING gin ((tags::jsonb))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS pancake_orders_tags_index');
        }
    }
};
