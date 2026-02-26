<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Coa extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'coa_no',
        'coa_category_id',
        'amount',
        'debit',
        'credit',
        'date',
        'posted_date',
        'posted',
        'type',
        'description'
    ];

    public function category()
    {
        return $this->belongsTo(CategoryCoa::class, 'coa_category_id');
    }
}
