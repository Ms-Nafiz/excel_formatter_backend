<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'full_name',
        'customer_type',
        'category',
        'status',
        'collector_name',
        'area_name',
        'building_name',
        'house_no',
        'flat_no',
        'add_combined',
        'raw_data_json',
    ];

    protected $casts = [
        'raw_data_json' => 'array',
    ];

    public function monthlyBillings(): HasMany
    {
        return $this->hasMany(CustomerMonthlyBilling::class, 'master_customer_id');
    }

    public function collections(): HasMany
    {
        return $this->hasMany(CustomerCollection::class, 'master_customer_id');
    }
}
