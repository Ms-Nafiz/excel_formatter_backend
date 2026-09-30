<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Collector extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'phone', 'status'];

    public function areas()
    {
        return $this->belongsToMany(Area::class);
    }
}
