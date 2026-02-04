<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'category_id',
        'code',
        'name',
        'image',
        'price',
        'stock',
        'description',
        'care',
        'design'
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
