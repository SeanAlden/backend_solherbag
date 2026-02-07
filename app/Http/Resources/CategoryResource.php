<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category_code' => $this->code,
            'category_name' => $this->name,
            'meta' => [
                'description' => $this->description ?? 'No description provided.',
                'slug' => str($this->name)->slug(),
            ],
            'timestamps' => [
                'created_at' => $this->created_at?->toDateTimeString(),
            ]
        ];
    }
}
