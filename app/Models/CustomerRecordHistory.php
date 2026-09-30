<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerRecordHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_monthly_billing_id',
        'customer_record_id',
        'processed_file_id',
        'user_id',
        'customer_id',
        'customer_name',
        'field_name',
        'old_value',
        'new_value',
        'edited_by',
    ];

    public function customerMonthlyBilling(): BelongsTo
    {
        return $this->belongsTo(CustomerMonthlyBilling::class, 'customer_monthly_billing_id');
    }

    public function customerRecord(): BelongsTo
    {
        return $this->belongsTo(CustomerMonthlyBilling::class, 'customer_monthly_billing_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function processedFile(): BelongsTo
    {
        return $this->belongsTo(ProcessedFile::class, 'processed_file_id');
    }
}
