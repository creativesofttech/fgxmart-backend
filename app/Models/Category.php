<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $fillable = ['name', 'code', 'slug', 'image', 'sorting_order', 'status', 'parent_id', 'short_description'];

    protected $casts = [
        'status' => 'boolean',
    ];

    protected $appends = ['image_url', 'thumbnail_url'];

    public function parent() { return $this->belongsTo(self::class, 'parent_id'); }
    public function children() { return $this->hasMany(self::class, 'parent_id'); }
    public function products() { return $this->hasMany(Product::class); }

    public function getImageUrlAttribute()
    {
        return $this->image ? asset('storage/' . $this->image) : null;
    }

    public function getThumbnailUrlAttribute()
    {
        if (!$this->image)
            return null;
        return asset('storage/' . \App\Services\ImageService::getThumbnailPath($this->image));
    }
}
