<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'html_description',
        'package_length',
        'package_width',
        'package_height',
        'weight_grams',
        'gallery',
        'seo_title',
        'seo_description',
        'additional_info',
    ];

    protected $casts = [
        'gallery' => 'array',
        'additional_info' => 'array',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
