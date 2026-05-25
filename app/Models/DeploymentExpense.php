<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeploymentExpense extends Model
{
    protected $fillable = [
        'deployment_id',
        'description',
        'amount',
        'expense_date',
        'category',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
    ];

    /**
     * Get the deployment that owns this expense
     */
    public function deployment()
    {
        return $this->belongsTo(Deployment::class);
    }
}
