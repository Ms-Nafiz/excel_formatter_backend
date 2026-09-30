<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'processed_file_id',
        'billing_month',
        'row_index',
        'collector_name',
        'area_name',
        'building_name',
        'house_no',
        'flat_no',
        'add_combined',
        'customer_type',
        'category',
        'child_count',
        'status',
        'full_name',
        'customer_id',
        'monthly_rent',
        'advance',
        'previous_dues',
        'discount',
        'actual_bill',
        'fifty_percent',
        'target',
        'raw_data_json',
    ];

    protected $casts = [
        'monthly_rent' => 'float',
        'advance' => 'float',
        'previous_dues' => 'float',
        'discount' => 'float',
        'actual_bill' => 'float',
        'fifty_percent' => 'float',
        'target' => 'float',
        'raw_data_json' => 'array',
    ];

    public function processedFile(): BelongsTo
    {
        return $this->belongsTo(ProcessedFile::class, 'processed_file_id');
    }
}
