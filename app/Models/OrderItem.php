<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'product_name',
        'quantity',
        'unit_price',
        'subtotal',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function deliveryNoteItems()
    {
        return $this->hasMany(\App\Models\DeliveryNoteItem::class, 'order_item_id');
    }

    // app/Models/OrderItem.php

public function features()
{
    return $this->belongsToMany(Feature::class, 'order_item_feature')
                ->withPivot('extra_price')
                ->withTimestamps();
}


}
