<?php

namespace App\Services;

use App\Models\Address;
use Illuminate\Support\Facades\DB;

class AddressService
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }

    public function createAddress(array $data, $user)
    {
        return DB::transaction(function () use ($data, $user) {
            if ($data['is_default'] ?? false) {
                $this->resetDefaultAddress($user);
            }

            return $user->addresses()->create($data);
        });
    }

    public function updateAddress(Address $address, array $data, $user)
    {
        return DB::transaction(function () use ($address, $data, $user) {
            if ($data['is_default'] ?? false) {
                $this->resetDefaultAddress($user);
            }

            $address->update($data);
            return $address->fresh();
        });
    }

    protected function resetDefaultAddress($user)
    {
        $user->addresses()->where('is_default', true)->update(['is_default' => false]);
    }
}
