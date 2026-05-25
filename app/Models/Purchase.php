<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Purchase extends Model
{
    protected $fillable = ['product_reference', 'quantity', 'purchase_date', 'client_id', 'invoice_id', 'price'];

    public function client() { return $this->belongsTo(Client::class); }
public function invoice() { return $this->belongsTo(Invoice::class); }
}
