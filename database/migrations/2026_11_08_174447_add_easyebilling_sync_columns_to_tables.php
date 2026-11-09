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
        // 1. Add sync columns to customer_record_histories
        if (Schema::hasTable('customer_record_histories')) {
            Schema::table('customer_record_histories', function (Blueprint $table) {
                if (!Schema::hasColumn('customer_record_histories', 'billing_sync_status')) {
                    $table->string('billing_sync_status', 20)->default('pending')->after('edited_by')->index();
                }
                if (!Schema::hasColumn('customer_record_histories', 'billing_sync_error')) {
                    $table->text('billing_sync_error')->nullable()->after('billing_sync_status');
                }
                if (!Schema::hasColumn('customer_record_histories', 'billing_synced_at')) {
                    $table->timestamp('billing_synced_at')->nullable()->after('billing_sync_error');
                }
                if (!Schema::hasColumn('customer_record_histories', 'billing_pk')) {
                    $table->string('billing_pk', 100)->nullable()->after('billing_synced_at')->index();
                }
            });
        }

        // 2. Add billing_pk to customer_monthly_billings
        if (Schema::hasTable('customer_monthly_billings')) {
            Schema::table('customer_monthly_billings', function (Blueprint $table) {
                if (!Schema::hasColumn('customer_monthly_billings', 'billing_pk')) {
                    $table->string('billing_pk', 100)->nullable()->after('customer_id')->index();
                }
            });
        }

        // 3. Add billing_pk to master_customers
        if (Schema::hasTable('master_customers')) {
            Schema::table('master_customers', function (Blueprint $table) {
                if (!Schema::hasColumn('master_customers', 'billing_pk')) {
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
        if (Schema::hasTable('customer_record_histories')) {
            Schema::table('customer_record_histories', function (Blueprint $table) {
                $table->dropColumn(['billing_sync_status', 'billing_sync_error', 'billing_synced_at', 'billing_pk']);
            });
        }

        if (Schema::hasTable('customer_monthly_billings')) {
            Schema::table('customer_monthly_billings', function (Blueprint $table) {
                $table->dropColumn(['billing_pk']);
            });
        }

        if (Schema::hasTable('master_customers')) {
            Schema::table('master_customers', function (Blueprint $table) {
                $table->dropColumn(['billing_pk']);
            });
        }
    }
};
