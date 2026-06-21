<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BannerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $imageUrls = $this->image_url;

        return [
            'image1' => $imageUrls[0] ?? null,
            'image2' => $imageUrls[1] ?? null,
            'image3' => $imageUrls[2] ?? null,
            'image4' => $imageUrls[3] ?? null,
            'video' => $this->video_url,
            'video_url' => $this->video_url,
            'banner_title' => $this->banner_title,
            'banner_description' => $this->banner_description,
            'video_title' => $this->video_title,
            'video_description' => $this->video_description,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
        ];
    }
}
