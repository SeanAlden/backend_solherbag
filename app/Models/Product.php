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
        'variant_images',
        'variant_video',
        'price',
        'discount_price',
        'stock',
        'description',
        'care',
        'design',
        'status'
    ];

    protected $casts = [
        'variant_images' => 'array',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
