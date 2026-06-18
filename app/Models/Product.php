<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Product extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'short_description',
        'discount_percentage',
        'category_id',
        'is_active',
        'is_collection',
        'track_stock',
        'weight_grams',

        'brand',
        'base_material',
        'plating',
        'gemstone',
        'design',
        'occasion',
        'ideal_for',
        'package_contents',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_collection' => 'boolean',
        'track_stock' => 'boolean',
        'weight_grams' => 'integer',
        'discount_percentage' => 'decimal:2',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function primaryImage(): HasMany
    {
        return $this->hasMany(ProductImage::class)->where('is_primary', true);
    }

    public function sizes(): HasMany
    {
        return $this->hasMany(ProductSize::class)->orderBy('id');
    }

    public function wishlists(): HasMany
    {
        return $this->hasMany(Wishlist::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(CustomerReview::class);
    }

    public function webReviews(): HasMany
    {
        return $this->hasMany(CustomerReview::class)
            ->where('on_web_show', true)
            ->orderByDesc('created_at');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($product) {
            if (empty($product->slug)) {
                $product->slug = Str::slug($product->name);
            }
        });

        static::updating(function ($product) {
            if ($product->isDirty('name') && empty($product->slug)) {
                $product->slug = Str::slug($product->name);
            }
        });
    }
}

