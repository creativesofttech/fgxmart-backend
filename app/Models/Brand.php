<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{
    protected $casts = ['status' => 'boolean', 'sort_order' => 'integer'];
    protected $fillable = [
        'name',
        'slug',
        'brand_image',
        'status'
    ];

    protected $appends = ['image_url'];

    public function getImageUrlAttribute()
    {
        return $this->brand_image ? asset('storage/' . $this->brand_image) : null;
    }
}
