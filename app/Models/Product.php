<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'price' => 'decimal:2',
        'discount_price' => 'decimal:2',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function transactionDetails(): HasMany
    {
        return $this->hasMany(TransactionDetail::class, 'product_id', 'id');
    }

    public function stocks()
    {
        return $this->hasMany(ProductStock::class)->orderBy('created_at', 'asc');
    }
}
