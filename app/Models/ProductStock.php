<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductStock extends Model
{
    protected $fillable = [
        'product_id',
        'batch_code',
        'quantity',
        'initial_quantity'
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
