<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Leads for the Segmentation Tracker, plus the built-in CRA role they are assigned to.
     */
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('order_id')->unique();
            $table->string('tracking_number')->nullable();
            $table->string('customer_name');
            $table->string('phone_number')->index();
            $table->string('product_name');
            $table->unsignedInteger('qty');
            $table->date('delivered_date');
            $table->unsignedInteger('consumption_days');
            // The lead day: when the customer is estimated to run out.
            $table->date('est_out_of_stock_date')->index();
            $table->string('lead_type'); // crd | new
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->string('status')->nullable();
            $table->foreignId('status_updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('status_updated_at')->nullable();
            $table->timestamps();

            $table->index(['est_out_of_stock_date', 'assigned_to']);
        });

        $now = now();
        DB::table('roles')->insertOrIgnore([
            'slug' => 'cra',
            'name' => 'CRA',
            'description' => 'Receives Segmentation Tracker leads and updates their status.',
            'permissions' => json_encode(['segmentation.view']),
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
        Schema::dropIfExists('leads');

        // Only remove the CRA role if nobody holds it.
        $craId = DB::table('roles')->where('slug', 'cra')->value('id');
        if ($craId && ! DB::table('users')->where('role_id', $craId)->exists()) {
            DB::table('roles')->where('id', $craId)->delete();
        }
    }
};
