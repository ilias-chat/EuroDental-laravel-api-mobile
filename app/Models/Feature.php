<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Feature extends Model
{
    protected $fillable = [
        'product_id',
        'name',
        'description',
        'extra_price',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function orderItems()
    {
        return $this->belongsToMany(OrderItem::class, 'order_item_feature')
                    ->withPivot('extra_price')
                    ->withTimestamps();
    }

}
