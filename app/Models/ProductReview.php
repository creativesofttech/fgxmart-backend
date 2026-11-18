<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductReview extends Model
{
    protected $casts = ['rating' => 'float', 'is_approved' => 'boolean', 'is_verified_purchase' => 'boolean'];
    protected $fillable = [
        'user_id',
        'product_id',
        'rating',
        'review',
        'image_path',
        'is_approved',
        'is_verified_purchase'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
