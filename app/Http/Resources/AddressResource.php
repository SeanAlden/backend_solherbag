<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AddressResource extends JsonResource
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
            'receiver' => [
                'first_name' => $this->first_name_address,
                'last_name' => $this->last_name_address,
                'full_name' => "{$this->first_name_address} {$this->last_name_address}",
            ],
            'details' => [
                'region' => $this->region,
                'location' => $this->address_location,
                'type' => $this->location_type ?? 'other',
                'city' => $this->city,
                'province' => $this->province,
                'postal_code' => $this->postal_code,
            ],
            'is_default' => (bool) $this->is_default,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
        ];
    }
}
