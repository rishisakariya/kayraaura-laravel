<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Support\PublicStorage;

class Banner extends Model
{
    use HasFactory;

    protected $appends = [
        'image_url',
        'video_url',
    ];

    protected $fillable = [
        'image',
        'video',
        'banner_title',
        'banner_description',
        'video_title',
        'video_description',
        'sort_order',
    ];

    protected $casts = [
        'image' => 'array',
        'sort_order' => 'integer',
    ];

    public static function current(): self
    {
        return static::query()->firstOrCreate(
            ['id' => 1],
            [
                'image' => [],
                'video' => null,
                'banner_title' => null,
                'banner_description' => null,
                'video_title' => null,
                'video_description' => null,
                'sort_order' => 1,
            ]
        );
    }

    public function getImageUrlAttribute(): array
    {
        return collect($this->image ?? [])
            ->map(fn (string $path) => $this->resolveMediaUrl($path))
            ->filter()
            ->values()
            ->all();
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
