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
        return [
            'id' => $this->id,
            'image' => $this->image,
            'image_url' => $this->image_url,
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
