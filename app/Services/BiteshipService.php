<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class BiteshipService
{
    protected $baseUrl = 'https://api.biteship.com/v1';
    protected $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.biteship.api_key');
    }

    // 1. CEK ONGKIR
    public function getRates($destinationPostalCode, $weight = 1000)
    {
        $response = Http::withHeaders([
            'Authorization' => $this->apiKey,
            'Content-Type' => 'application/json'
        ])->post("{$this->baseUrl}/rates/couriers", [
            'origin_postal_code' => config('services.biteship.origin_postal_code'),
            'destination_postal_code' => $destinationPostalCode,
            'couriers' => 'jne,sicepat,jnt', // Tentukan kurir yang aktif
            'items' => [
                ['weight' => $weight] // Default 1kg, bisa dibuat dinamis jika tabel product punya kolom weight
            ]
        ]);

        return $response->json();
    }

    // 2. CREATE ORDER (Dijalankan hanya saat status 'processing')
    // public function createOrder($transaction)
    // {
    //     // Load relasi address jika belum
    //     $transaction->loadMissing('address');

    //     $response = Http::withHeaders([
    //         'Authorization' => $this->apiKey,
    //         'Content-Type' => 'application/json'
    //     ])->post("{$this->baseUrl}/orders", [
    //         'shipper_contact_name' => 'Solher Store',
    //         'shipper_contact_phone' => '08123456789',
    //         'origin_postal_code' => config('services.biteship.origin_postal_code'),
    //         'destination_postal_code' => $transaction->address->details['postal_code'],
    //         'destination_contact_name' => $transaction->address->receiver['first_name'],
    //         'destination_contact_phone' => '08123456789', // Ambil dari user/address phone
    //         'destination_address' => $transaction->address->details['location'],
    //         'courier_company' => $transaction->courier_company,
    //         'courier_type' => $transaction->courier_type,
    //         'delivery_type' => 'now', // Request pickup sekarang
    //         'items' => [
    //             [
    //                 'name' => 'Solher Products',
    //                 'value' => (int) $transaction->total_amount,
    //                 'quantity' => 1,
    //                 'weight' => 1000
    //             ]
    //         ]
    //     ]);

    //     return $response->json();
    // }

    public function createOrder($transaction)
    {
        // Load relasi address dan user
        $transaction->loadMissing(['address', 'user']);

        $response = Http::withHeaders([
            'Authorization' => $this->apiKey,
            'Content-Type' => 'application/json'
        ])->post("{$this->baseUrl}/orders", [
            'shipper_contact_name' => 'Solher Store',
            'shipper_contact_phone' => '08123456789',
            'origin_postal_code' => config('services.biteship.origin_postal_code'),

            // PERBAIKAN DISINI: Panggil kolom langsung
            'destination_postal_code' => $transaction->address->postal_code,
            'destination_contact_name' => trim($transaction->address->first_name_address . ' ' . $transaction->address->last_name_address),
            'destination_contact_phone' => '08123456789', // Ganti dengan nomor telepon user jika ada di tabel user
            'destination_address' => $transaction->address->address_location,

            'courier_company' => $transaction->courier_company,
            'courier_type' => $transaction->courier_type,
            'delivery_type' => 'now',
            'items' => [
                [
                    'name' => 'Solher Products',
                    'value' => (int) $transaction->total_amount,
                    'quantity' => 1,
                    'weight' => 1000
                ]
            ]
        ]);

        return $response->json();
    }
}
