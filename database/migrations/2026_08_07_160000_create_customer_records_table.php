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
        Schema::create('customer_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('processed_file_id')->constrained('processed_files')->cascadeOnDelete();
            $table->integer('row_index')->index();
            $table->string('collector_name')->nullable()->index();
            $table->string('area_name')->nullable()->index();
            $table->string('building_name')->nullable();
            $table->string('house_no')->nullable();
            $table->string('flat_no')->nullable();
            $table->string('add_combined')->nullable();
            $table->string('customer_type')->default('Analog')->index();
            $table->string('status')->default('Active')->index();
            $table->string('full_name')->nullable();
            $table->string('customer_id')->nullable();
            $table->decimal('monthly_rent', 12, 2)->default(0.00);
            $table->decimal('advance', 12, 2)->default(0.00);
            $table->decimal('previous_dues', 12, 2)->default(0.00);
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
        Schema::dropIfExists('customer_records');
    }
};
