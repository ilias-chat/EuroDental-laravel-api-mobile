<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskProduct extends Model
{
    protected $fillable = ['product_reference', 'quantity', 'task_id', 'purchase_date', 'price'];

    public function task() { return $this->belongsTo(Task::class); }
}
