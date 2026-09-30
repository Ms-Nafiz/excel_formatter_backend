<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerCollection extends Model
{
    use HasFactory;

    protected $fillable = [
        'master_customer_id',
        'customer_id',
        'customer_name',
        'customer_type',
        'area_name',
        'address',
        'billing_month',
        'payment_date',
        'amount_paid',
        'payment_method',
        'collector_name',
        'receipt_no',
        'remarks',
    ];

    protected $casts = [
        'payment_date' => 'datetime',
        'amount_paid' => 'float',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'master_customer_id');
    }
}
