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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('customer_id')->index();
            $table->string('full_name')->nullable();
            $table->string('customer_type')->default('Analog')->index();
            $table->string('category')->default('Army')->index();
            $table->string('status')->default('Active')->index();
            $table->string('collector_name')->nullable()->index();
            $table->string('area_name')->nullable()->index();
            $table->string('building_name')->nullable();
            $table->string('house_no')->nullable();
            $table->string('flat_no')->nullable();
            $table->string('add_combined')->nullable();
            $table->json('raw_data_json')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
