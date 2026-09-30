<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class ProcessedFile extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'original_name',
        'billing_month',
        'stored_name',
        'file_path',
        'row_count',
        'file_size',
        'status',
        'formatting_options',
        'error_message',
    ];

    protected $casts = [
        'formatting_options' => 'array',
        'row_count' => 'integer',
        'file_size' => 'integer',
    ];

    protected $appends = [
        'download_url',
        'formatted_file_size',
        'formatted_name',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function customerRecords()
    {
        return $this->hasMany(CustomerMonthlyBilling::class, 'processed_file_id');
    }

    public function monthlyBillings()
    {
        return $this->hasMany(CustomerMonthlyBilling::class, 'processed_file_id');
    }

    public function getDownloadUrlAttribute(): string
    {
        return url(Storage::url($this->file_path));
    }

    public function getFormattedFileSizeAttribute(): string
    {
        $bytes = $this->file_size;
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }

    public function getFormattedNameAttribute(): string
    {
        $cleanName = pathinfo($this->original_name, PATHINFO_FILENAME);
        if (preg_match('/^(book\d*|sheet\d*|data|input|file|sample|raw.*)$/i', trim($cleanName))) {
            $cleanName = 'Customer_Billing_Report';
        } else {
            $cleanName = \Illuminate\Support\Str::slug($cleanName, '_');
        }

        $monthStr = $this->billing_month ? \Illuminate\Support\Str::slug($this->billing_month, '_') : date('F_Y');
        return 'Formatted_' . $cleanName . '_' . $monthStr . '.xlsx';
    }
}
