<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Support\PublicStorage;

class Banner extends Model
{
    use HasFactory;

    private const IMAGE_FIELDS = ['image1', 'image2', 'image3', 'image4'];

    protected $appends = [
        'image1_url',
        'image2_url',
        'image3_url',
        'image4_url',
        'video_url',
    ];

    protected $fillable = [
        'image1',
        'image2',
        'image3',
        'image4',
        'video',
        'banner_title',
        'banner_description',
        'video_title',
        'video_description',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public static function current(): self
    {
        return static::query()->findOrFail(1);
    }

    public static function imageFields(): array
    {
        return self::IMAGE_FIELDS;
    }

    public function getImage1UrlAttribute(): ?string
    {
        return $this->resolveMediaUrl($this->image1);
    }

    public function getImage2UrlAttribute(): ?string
    {
        return $this->resolveMediaUrl($this->image2);
    }

    public function getImage3UrlAttribute(): ?string
    {
        return $this->resolveMediaUrl($this->image3);
    }

    public function getImage4UrlAttribute(): ?string
    {
        return $this->resolveMediaUrl($this->image4);
    }

    public function getVideoUrlAttribute(): ?string
    {
        return $this->resolveMediaUrl($this->video);
    }

    private function resolveMediaUrl(?string $path): ?string
    {
        return PublicStorage::url($path);
    }
}
