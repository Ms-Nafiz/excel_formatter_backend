<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. In customer_record_histories:
        // Drop foreign key to customer_records and add customer_monthly_billing_id
        if (Schema::hasTable('customer_record_histories')) {
            Schema::table('customer_record_histories', function (Blueprint $table) {
                // Drop FK constraint safely
                $table->dropForeign(['customer_record_id']);
            });

            Schema::table('customer_record_histories', function (Blueprint $table) {
                $table->unsignedBigInteger('customer_record_id')->nullable()->change();
                $table->unsignedBigInteger('customer_monthly_billing_id')->nullable()->after('customer_record_id')->index();
            });

            // Populate customer_monthly_billing_id from existing monthly billings matching file_id & customer_id
            $histories = DB::table('customer_record_histories')->get();
            foreach ($histories as $h) {
                if ($h->processed_file_id && $h->customer_id) {
                    $mb = DB::table('customer_monthly_billings')
                        ->where('processed_file_id', $h->processed_file_id)
                        ->where('customer_id', $h->customer_id)
                        ->first();
                    if ($mb) {
                        DB::table('customer_record_histories')
                            ->where('id', $h->id)
                            ->update(['customer_monthly_billing_id' => $mb->id]);
                    }
                }
            }

            Schema::table('customer_record_histories', function (Blueprint $table) {
                $table->foreign('customer_monthly_billing_id')
                    ->references('id')
                    ->on('customer_monthly_billings')
                    ->onDelete('cascade');
            });
        }

        // 2. Ensure every row in customer_monthly_billings has master_customer_id populated
        $missingMaster = DB::table('customer_monthly_billings')
            ->whereNull('master_customer_id')
            ->whereNotNull('customer_id')
            ->get();

        foreach ($missingMaster as $rec) {
            $existingCustomer = DB::table('customers')->where('customer_id', $rec->customer_id)->first();
            if (!$existingCustomer) {
                $cid = DB::table('customers')->insertGetId([
                    'customer_id'    => $rec->customer_id,
                    'full_name'      => $rec->full_name,
                    'customer_type'  => $rec->customer_type,
                    'category'       => $rec->category,
                    'status'         => $rec->status,
                    'collector_name' => $rec->collector_name,
                    'area_name'      => $rec->area_name,
                    'building_name'  => $rec->building_name,
                    'house_no'       => $rec->house_no,
                    'flat_no'        => $rec->flat_no,
                    'add_combined'   => $rec->add_combined,
                    'raw_data_json'  => $rec->raw_data_json,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
            } else {
                $cid = $existingCustomer->id;
            }

            DB::table('customer_monthly_billings')
                ->where('id', $rec->id)
                ->update(['master_customer_id' => $cid]);
        }

        // 3. Drop physical table customer_records and replace with SQL View for seamless backward compatibility
        Schema::dropIfExists('customer_records');

        DB::statement("
            CREATE VIEW customer_records AS
            SELECT 
                id,
                processed_file_id,
                billing_month,
                row_index,
                collector_name,
                area_name,
                building_name,
                house_no,
                flat_no,
                add_combined,
                customer_type,
                category,
                child_count,
                status,
                full_name,
                customer_id,
                monthly_rent,
                advance,
                previous_dues,
                discount,
                actual_bill,
                fifty_percent,
                target,
                raw_data_json,
                created_at,
                updated_at
            FROM customer_monthly_billings
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("DROP VIEW IF EXISTS customer_records");

        // Recreate customer_records table if rolled back
        Schema::create('customer_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('processed_file_id')->constrained('processed_files')->cascadeOnDelete();
            $table->string('billing_month')->nullable()->index();
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
            $table->string('customer_id')->nullable()->index();
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

        if (Schema::hasTable('customer_record_histories')) {
            Schema::table('customer_record_histories', function (Blueprint $table) {
                $table->dropForeign(['customer_monthly_billing_id']);
                $table->dropColumn('customer_monthly_billing_id');
                $table->foreign('customer_record_id')->references('id')->on('customer_records')->onDelete('cascade');
            });
        }
    }
};
