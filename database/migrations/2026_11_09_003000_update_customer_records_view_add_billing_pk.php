<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('CREATE OR REPLACE VIEW customer_records AS SELECT id, processed_file_id, billing_month, row_index, collector_name, area_name, building_name, house_no, flat_no, add_combined, customer_type, category, child_count, status, full_name, customer_id, billing_pk, monthly_rent, advance, previous_dues, discount, actual_bill, fifty_percent, target, raw_data_json, created_at, updated_at FROM customer_monthly_billings');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('CREATE OR REPLACE VIEW customer_records AS SELECT id, processed_file_id, billing_month, row_index, collector_name, area_name, building_name, house_no, flat_no, add_combined, customer_type, category, child_count, status, full_name, customer_id, monthly_rent, advance, previous_dues, discount, actual_bill, fifty_percent, target, raw_data_json, created_at, updated_at FROM customer_monthly_billings');
    }
};
