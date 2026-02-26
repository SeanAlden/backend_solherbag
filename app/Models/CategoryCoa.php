<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CategoryCoa extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_name'
    ];

    public function coas()
    {
        return $this->hasMany(Coa::class, 'coa_category_id');
    }
}
