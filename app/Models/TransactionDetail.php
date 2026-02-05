<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TransactionDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'product_id',
        'quantity',
        'price',
    ];

    /**
     * Relasi ke Transaction
     * TransactionDetail belongsTo Transaction
     */
    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * Relasi ke Product
     * TransactionDetail belongsTo Product
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
