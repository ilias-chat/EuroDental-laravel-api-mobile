<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Right extends Model
{
    protected $fillable = ['id_profile', 'invoices_read', 'invoices_write', 'clients_read', 'clients_write', 'products_read', 'products_write', 'tasks_read', 'tasks_write', 'users_read', 'users_write', 'mobile_tasks_read', 'mobile_tasks_write', 'mobile_stock_read', 'mobile_stock_write'];

    public function profile() { return $this->belongsTo(Profile::class, 'id_profile'); }
}
