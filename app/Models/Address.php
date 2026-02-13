<?php

// namespace App\Models;

// use Illuminate\Database\Eloquent\Model;

// class Address extends Model
// {
//     protected $fillable = [
//         'user_id',
//         'region',
//         'first_name_address',
//         'last_name_address',
//         'address_location',
//         'location_type',
//         'city',
//         'province',
//         'postal_code',
//         'is_default'
//     ];

//     public function user()
//     {
//         return $this->belongsTo(User::class);
//     }
// }

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Address extends Model
{
    protected $fillable = [
        'user_id', 'region', 'first_name_address', 'last_name_address',
        'address_location', 'location_type', 'city', 'province',
        'postal_code', 'is_default'
    ];

    protected $casts = [
        'receiver' => 'array', // Tambahkan juga jika receiver formatnya JSON
        'is_default' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
