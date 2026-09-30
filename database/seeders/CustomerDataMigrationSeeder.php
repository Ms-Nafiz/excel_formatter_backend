<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Customer;
use App\Models\CustomerMonthlyBilling;
use App\Models\CustomerRecord;

class CustomerDataMigrationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        @ini_set('memory_limit', '512M');
        $this->command->info('Migrating customer_records into master customers and customer_monthly_billings...');

        CustomerMonthlyBilling::query()->delete();
        Customer::query()->delete();

        $now = now()->toDateTimeString();
        $masterProfiles = [];
        $count = 0;

        // Step 1: Collect unique master profiles in memory & insert master customers in batches
        CustomerRecord::orderBy('id', 'asc')->chunk(2000, function ($records) use (&$masterProfiles, &$count, $now) {
            foreach ($records as $rec) {
                $cid = trim((string)$rec->customer_id);
                if ($cid === '') {
                    continue;
                }

                if (!isset($masterProfiles[$cid])) {
                    $masterProfiles[$cid] = [
                        'customer_id'    => $cid,
                        'full_name'      => $rec->full_name,
                        'customer_type'  => $rec->customer_type ?? 'Analog',
                        'category'       => $rec->category ?? 'Army',
                        'status'         => $rec->status ?? 'Active',
                        'collector_name' => $rec->collector_name,
                        'area_name'      => $rec->area_name,
                        'building_name'  => $rec->building_name,
                        'house_no'       => $rec->house_no,
                        'flat_no'        => $rec->flat_no,
                        'add_combined'   => $rec->add_combined,
                        'raw_data_json'  => is_array($rec->raw_data_json) ? json_encode($rec->raw_data_json) : $rec->raw_data_json,
                        'created_at'     => $now,
                        'updated_at'     => $now,
                    ];
                } else {
                    if ($rec->full_name) $masterProfiles[$cid]['full_name'] = $rec->full_name;
                    if ($rec->customer_type) $masterProfiles[$cid]['customer_type'] = $rec->customer_type;
                    if ($rec->category) $masterProfiles[$cid]['category'] = $rec->category;
                    if ($rec->status) $masterProfiles[$cid]['status'] = $rec->status;
                    if ($rec->collector_name) $masterProfiles[$cid]['collector_name'] = $rec->collector_name;
                    if ($rec->area_name) $masterProfiles[$cid]['area_name'] = $rec->area_name;
                    if ($rec->building_name) $masterProfiles[$cid]['building_name'] = $rec->building_name;
                    if ($rec->house_no) $masterProfiles[$cid]['house_no'] = $rec->house_no;
                    if ($rec->flat_no) $masterProfiles[$cid]['flat_no'] = $rec->flat_no;
                    if ($rec->add_combined) $masterProfiles[$cid]['add_combined'] = $rec->add_combined;
                }
                $count++;
            }
        });

        // Insert Master Profiles in a single transaction batch
        DB::transaction(function () use ($masterProfiles) {
            foreach (array_chunk($masterProfiles, 500) as $chunk) {
                Customer::insert($chunk);
            }
        });

        // Map customer_id -> master_id
        $customerIdToMasterIdMap = Customer::pluck('id', 'customer_id')->toArray();

        // Step 2: Stream insertion of monthly billings chunk by chunk
        CustomerRecord::orderBy('id', 'asc')->chunk(1000, function ($records) use ($customerIdToMasterIdMap, $now) {
            $billingsChunk = [];
            foreach ($records as $rec) {
                $cid = trim((string)$rec->customer_id);
                if ($cid === '') {
                    continue;
                }

                $billingsChunk[] = [
                    'master_customer_id' => $customerIdToMasterIdMap[$cid] ?? null,
                    'processed_file_id'  => $rec->processed_file_id,
                    'customer_id'         => $cid,
                    'billing_month'      => $rec->billing_month ?? '',
                    'row_index'          => $rec->row_index ?? 0,
                    'collector_name'     => $rec->collector_name,
                    'area_name'          => $rec->area_name,
                    'building_name'      => $rec->building_name,
                    'house_no'           => $rec->house_no,
                    'flat_no'            => $rec->flat_no,
                    'add_combined'       => $rec->add_combined,
                    'customer_type'      => $rec->customer_type ?? 'Analog',
                    'category'           => $rec->category ?? 'Army',
                    'child_count'        => $rec->child_count ?? 0,
                    'status'             => $rec->status ?? 'Active',
                    'full_name'          => $rec->full_name,
                    'monthly_rent'       => $rec->monthly_rent ?? 0,
                    'advance'            => $rec->advance ?? 0,
                    'previous_dues'      => $rec->previous_dues ?? 0,
                    'discount'           => $rec->discount ?? 0,
                    'actual_bill'        => $rec->actual_bill ?? 0,
                    'fifty_percent'      => $rec->fifty_percent ?? 0,
                    'target'             => $rec->target ?? 0,
                    'raw_data_json'      => is_array($rec->raw_data_json) ? json_encode($rec->raw_data_json) : $rec->raw_data_json,
                    'created_at'         => $now,
                    'updated_at'         => $now,
                ];
            }

            if (!empty($billingsChunk)) {
                DB::transaction(function () use ($billingsChunk) {
                    CustomerMonthlyBilling::insert($billingsChunk);
                });
            }
        });

        $masterCount = Customer::count();
        $billingCount = CustomerMonthlyBilling::count();

        $this->command->info("Data migration complete! Created {$masterCount} master customer profiles and {$billingCount} monthly billing rows from {$count} source records.");
    }
}
