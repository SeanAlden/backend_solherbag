<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class TransferReceivePayment extends Model
{
    protected $guarded = ['id'];

    public function kreditCoa()
    {
        return $this->belongsTo(Coa::class, 'kredit_coa_id');
    }

    public function debitCoa()
    {
        return $this->belongsTo(Coa::class, 'debit_coa_id');
    }
}
