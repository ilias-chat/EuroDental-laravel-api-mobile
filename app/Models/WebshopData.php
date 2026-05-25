<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebshopData extends Model
{
    protected $table = 'webshop_data';

    protected $fillable = [
        'client_id',
        'first_name',
        'last_name',
        'address',
        'city_id',
        'phone_number',
        'validated',
    ];

    protected $casts = [
        'validated' => 'boolean',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }
}
