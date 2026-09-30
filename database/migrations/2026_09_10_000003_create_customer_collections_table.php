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
        Schema::create('customer_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('master_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('customer_id')->index();
            $table->string('billing_month')->nullable()->index();
            $table->dateTime('payment_date')->nullable()->index();
            $table->decimal('amount_paid', 12, 2)->default(0.00);
            $table->string('payment_method')->default('Cash');
            $table->string('collector_name')->nullable();
            $table->string('receipt_no')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_collections');
    }
};
