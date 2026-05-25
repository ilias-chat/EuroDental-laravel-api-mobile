<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryNoteItem extends Model
{
    protected $fillable = ['delivery_note_id', 'order_item_id', 'delivered_quantity'];

    public function deliveryNote()
    {
        return $this->belongsTo(DeliveryNote::class);
    }

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }
}

