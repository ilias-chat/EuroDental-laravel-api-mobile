<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Foundation\Auth\User as Authenticatable;

class Client extends Authenticatable
{
    protected $guard = 'client';

    protected $fillable = ['password', 'email', 'first_name', 'last_name', 'phone_number', 'fixed_phone_number', 'ice', 'address', 'city_id', 'description', 'image_id', 'source', 'validated', 'handled'];
    
    protected $hidden = ['password'];

    /**
     * Scope to only clients that are validated (created via admin or later validated).
     */
    public function scopeAdmin($query)
    {
        return $query->where('validated', true);
    }

    /**
     * First name: from client row or from webshop_data when client is webshop-only (null on clients table).
     */
    public function getFirstNameAttribute($value)
    {
        if ($value !== null && $value !== '') {
            return $value;
        }
        return $this->webshopData?->first_name;
    }

    /**
     * Last name: from client row or from webshop_data when client is webshop-only (null on clients table).
     */
    public function getLastNameAttribute($value)
    {
        if ($value !== null && $value !== '') {
            return $value;
        }
        return $this->webshopData?->last_name;
    }

    /**
     * Address: from client row or from webshop_data when client is webshop-only.
     */
    public function getAddressAttribute($value)
    {
        if ($value !== null && $value !== '') {
            return $value;
        }
        return $this->webshopData?->address;
    }

    /**
     * City ID: from client row or from webshop_data when client is webshop-only.
     */
    public function getCityIdAttribute($value)
    {
        if ($value !== null && $value !== '') {
            return $value;
        }
        return $this->webshopData?->city_id;
    }

    /**
     * Phone number: from client row or from webshop_data when client is webshop-only.
     */
    public function getPhoneNumberAttribute($value)
    {
        if ($value !== null && $value !== '') {
            return $value;
        }
        return $this->webshopData?->phone_number;
    }

    public function city() { return $this->belongsTo(City::class); }
    public function image() { return $this->belongsTo(Image::class); }
    public function webshopData() { return $this->hasOne(WebshopData::class); }
    public function tasks() { return $this->hasMany(Task::class); }
    public function invoices() { return $this->hasMany(Invoice::class); }
    public function orders() { return $this->hasMany(Order::class);}

}
