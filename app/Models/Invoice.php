<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $fillable = ['client_id', 'task_id', 'bill_date', 'amount_paid', 'is_paid', 'discount'];

    public function client() { return $this->belongsTo(Client::class); }
public function task() { return $this->belongsTo(Task::class); }
public function purchases() { return $this->hasMany(Purchase::class); }
}
