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
        Schema::table('processed_files', function (Blueprint $table) {
            $table->string('billing_month')->nullable()->after('original_name');
        });

        Schema::table('customer_records', function (Blueprint $table) {
            $table->string('billing_month')->nullable()->index()->after('processed_file_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('processed_files', function (Blueprint $table) {
            $table->dropColumn('billing_month');
        });

        Schema::table('customer_records', function (Blueprint $table) {
            $table->dropColumn('billing_month');
        });
    }
};
