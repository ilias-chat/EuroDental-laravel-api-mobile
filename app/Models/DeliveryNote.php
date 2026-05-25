<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryNote extends Model
{
    protected $fillable = ['order_id', 'delivery_date', 'notes'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function items()
    {
        return $this->hasMany(DeliveryNoteItem::class);
    }

    public function deliveryNoteItems()
    {
        return $this->hasMany(DeliveryNoteItem::class, 'delivery_note_id');
    }

}

