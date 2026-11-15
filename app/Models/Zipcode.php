<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Zipcode extends Model
{
    protected $fillable = ['country_id', 'zipcodes'];

    public function country()
    {
        return $this->belongsTo(Country::class);
    }
}
