<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('customer_collections', function (Blueprint $table) {
            if (!Schema::hasColumn('customer_collections', 'customer_name')) {
                $table->string('customer_name')->nullable()->after('customer_id');
            }
            if (!Schema::hasColumn('customer_collections', 'customer_type')) {
                $table->string('customer_type')->nullable()->after('customer_name');
            }
            if (!Schema::hasColumn('customer_collections', 'area_name')) {
                $table->string('area_name')->nullable()->after('customer_type');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_collections', function (Blueprint $table) {
            $table->dropColumn(['customer_name', 'customer_type', 'area_name']);
        });
    }
};
