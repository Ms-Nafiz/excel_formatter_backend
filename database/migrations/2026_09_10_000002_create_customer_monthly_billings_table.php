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
        Schema::create('customer_monthly_billings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('master_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('processed_file_id')->constrained('processed_files')->cascadeOnDelete();
            $table->string('customer_id')->index();
            $table->string('billing_month')->index();
            $table->integer('row_index')->default(0);
            $table->string('collector_name')->nullable()->index();
            $table->string('area_name')->nullable()->index();
            $table->string('building_name')->nullable();
            $table->string('house_no')->nullable();
            $table->string('flat_no')->nullable();
            $table->string('add_combined')->nullable();
            $table->string('customer_type')->default('Analog')->index();
            $table->string('category')->default('Army')->index();
            $table->integer('child_count')->default(0);
            $table->string('status')->default('Active')->index();
            $table->string('full_name')->nullable();
            $table->decimal('monthly_rent', 12, 2)->default(0.00);
            $table->decimal('advance', 12, 2)->default(0.00);
            $table->decimal('previous_dues', 12, 2)->default(0.00);
            $table->decimal('discount', 12, 2)->default(0.00);
            $table->decimal('actual_bill', 12, 2)->default(0.00);
            $table->decimal('fifty_percent', 12, 2)->default(0.00);
            $table->decimal('target', 12, 2)->default(0.00);
            $table->json('raw_data_json')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_monthly_billings');
    }
};
