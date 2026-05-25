<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Flipbook extends Model
{
    protected $casts = [
        'pages' => 'array',
    ];
    // app/Models/Flipbook.php
    protected $fillable = ['title', 'cover_image', 'pages'];
   
}