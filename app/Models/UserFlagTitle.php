<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserFlagTitle extends Model
{
    public const KIND_GREEN = 'green';

    public const KIND_RED = 'red';

    protected $fillable = [
        'kind',
        'title',
    ];

    public function flagEvents(): HasMany
    {
        return $this->hasMany(UserFlagEvent::class, 'user_flag_title_id');
    }
}
