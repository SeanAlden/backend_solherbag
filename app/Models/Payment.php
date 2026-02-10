<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'transaction_id',
        'external_id',
        'checkout_url',
        'amount',
        'status'
    ];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }
}
