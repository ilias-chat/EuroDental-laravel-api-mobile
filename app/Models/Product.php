<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class Product extends Model
{
    public const PLACEHOLDER_IMAGE = 'img/placeholder.png';
    protected $fillable = ['slug', 'product_name', 'id_category', 'id_sub_category', 'id_brand', 'show_price', 'price', 'stock_quantity', 'has_warranty', 'warranty_duration_months', 'description', 'reference', 'image_id', 'status', 'visibility'];

    protected $casts = [
        'show_price' => 'boolean',
    ];

    public function category() { return $this->belongsTo(Category::class, 'id_category'); }
    public function subCategory() { return $this->belongsTo(SubCategory::class, 'id_sub_category'); }
    public function brand() { return $this->belongsTo(Brand::class, 'id_brand'); }
    public function image() { return $this->belongsTo(Image::class, 'image_id'); }
    public function detail() { return $this->hasOne(ProductDetail::class); }
    public function features() { return $this->hasMany(Feature::class); }
    public function orderItems() { return $this->hasMany(\App\Models\OrderItem::class, 'product_id'); }


    public function getRouteKeyName()
    {
        return 'slug';
    }

    /**
     * Gallery image IDs from product_details.gallery (preserves order).
     *
     * @return array<int>
     */
    public function galleryImageIds(): array
    {
        $gallery = $this->detail?->gallery;

        return is_array($gallery) ? array_values(array_filter($gallery)) : [];
    }

    /**
     * Gallery Image models in gallery order.
     */
    public function orderedGalleryImages(): Collection
    {
        $ids = $this->galleryImageIds();

        if ($ids === []) {
            return collect();
        }

        $images = Image::whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)
            ->map(fn ($id) => $images->get($id))
            ->filter();
    }

    /**
     * Public URL paths for gallery images (empty collection if none).
     */
    public function galleryImageUrls(): Collection
    {
        return $this->orderedGalleryImages()->map(
            fn (Image $image) => asset('storage/' . $image->image_name)
        );
    }

    /**
     * First gallery image URL, or the site placeholder when gallery is empty.
     */
    public function displayImageUrl(): string
    {
        $first = $this->galleryImageUrls()->first();

        return $first ?? asset(self::PLACEHOLDER_IMAGE);
    }

    /**
     * URLs for the product page carousel: gallery only, or placeholder if empty.
     */
    public function displayImageUrlsForCarousel(): array
    {
        $urls = $this->galleryImageUrls()->values()->all();

        return $urls !== [] ? $urls : [asset(self::PLACEHOLDER_IMAGE)];
    }

}
