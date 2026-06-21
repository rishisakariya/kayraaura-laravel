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

    public static function current(): ?self
    {
        return static::orderBy('sort_order')
            ->orderBy('created_at', 'desc')
            ->first();
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
        if (!$path || $path === '') {
            return null;
        }

        return filter_var($path, FILTER_VALIDATE_URL)
            ? $path
            : Storage::disk('public')->url($path);
    }
}
