<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContactResource extends JsonResource
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
            'sender' => [
                'name' => $this->name,
                'email' => $this->email,
                'phone' => $this->phone ?? 'N/A',
                'is_registered' => $this->user_id !== null,
            ],
            'content' => [
                'message' => $this->description,
                'preview' => str($this->description)->limit(50),
            ],
            'metadata' => [
                'received_at' => $this->created_at->diffForHumans(),
                'full_date' => $this->created_at->toDateTimeString(),
            ],
        ];
    }
}
