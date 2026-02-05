<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'order_id',
        'total_amount',
        'status',
    ];

    /**
     * Relasi ke User
     * Transaction belongsTo User
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relasi ke TransactionDetail
     * Transaction hasMany TransactionDetail
     */
    public function details()
    {
        return $this->hasMany(TransactionDetail::class);
    }
}
