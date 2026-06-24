<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Support\MediaType;
use App\Support\PublicStorage;

class ProductImage extends Model
{
    protected $appends = [
        'image_url',
        'media_type',
    ];

    protected $fillable = [
        'product_id',
        'image_path',
        'alt_text',
        'sort_order',
        'is_primary',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function getImageUrlAttribute(): ?string
    {
        return PublicStorage::url($this->image_path);
    }

    public function getMediaTypeAttribute(): string
    {
        return MediaType::fromPath($this->image_path);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($image) {
            if ($image->is_primary) {
                // Set all other images for this product as non-primary
                static::where('product_id', $image->product_id)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }
        });

        static::updating(function ($image) {
            if ($image->is_primary && $image->isDirty('is_primary')) {
                // Set all other images for this product as non-primary
                static::where('product_id', $image->product_id)
                    ->where('id', '!=', $image->id)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }
        });
    }
}
