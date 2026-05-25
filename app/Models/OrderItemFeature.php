<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItemFeature extends Model
{
    protected $fillable = ['order_item_id', 'feature_id', 'extra_price'];

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function feature()
    {
        return $this->belongsTo(Feature::class);
    }
}

