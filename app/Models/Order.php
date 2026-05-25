<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'client_id',
        'created_by',
        'source',
        'confirmed_by',
        'order_number',
        'status',
        'total_price',
        'payment_method',
        'notes',
        'tax_rate',
        'tax_amount',
        'discount',
        'total_with_tax',
        'paid'
    ];    

    public function deliveryNotes()
    {
        return $this->hasMany(DeliveryNote::class);
    }


    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    protected static function booted()
    {
        static::creating(function ($order) {
            // Only auto-generate if order_number is not already set
            if (empty($order->order_number)) {
                $latestId = static::max('id') + 1;
                $order->order_number = 'ORD-' . str_pad($latestId, 6, '0', STR_PAD_LEFT);
            }
        });
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function totalPaid()
    {
        return $this->payments()->sum('amount');
    }

    public function remainingAmount()
    {
        return $this->total_with_tax - $this->totalPaid();
    }

    public function isFullyPaid()
    {
        return $this->payments()->sum('amount') >= $this->total_with_tax;
    }

}
