<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Settings → Niche: a niche is a set of products and the CRAs who get only those products' leads. A CRA is in one
     * niche at most; with no niche saved, nothing changes.
     */
    public function up(): void
    {
        Schema::create('niches', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('niche_product', function (Blueprint $table) {
            $table->foreignId('niche_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->primary(['niche_id', 'product_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('niche_id')->nullable()->after('role_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('niche_id');
        });
        Schema::dropIfExists('niche_product');
        Schema::dropIfExists('niches');
    }
};
