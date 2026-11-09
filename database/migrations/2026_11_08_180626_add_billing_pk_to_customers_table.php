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
        // 1. Add billing_pk to customers table
        if (Schema::hasTable('customers')) {
            Schema::table('customers', function (Blueprint $table) {
                if (!Schema::hasColumn('customers', 'billing_pk')) {
                    $table->string('billing_pk', 100)->nullable()->after('customer_id')->index();
                }
            });
        }

        // 2. Add billing_pk to customer_records table
        if (Schema::hasTable('customer_records')) {
            Schema::table('customer_records', function (Blueprint $table) {
                if (!Schema::hasColumn('customer_records', 'billing_pk')) {
                    $table->string('billing_pk', 100)->nullable()->after('customer_id')->index();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('customers')) {
            Schema::table('customers', function (Blueprint $table) {
                if (Schema::hasColumn('customers', 'billing_pk')) {
                    $table->dropColumn('billing_pk');
                }
            });
        }

        if (Schema::hasTable('customer_records')) {
            Schema::table('customer_records', function (Blueprint $table) {
                if (Schema::hasColumn('customer_records', 'billing_pk')) {
                    $table->dropColumn('billing_pk');
                }
            });
        }
    }
};
