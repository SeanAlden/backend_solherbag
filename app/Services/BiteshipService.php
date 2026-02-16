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

    // public function createOrder($transaction)
    // {
    //     // Load relasi address dan user
    //     $transaction->loadMissing(['address', 'user']);

    //     $response = Http::withHeaders([
    //         'Authorization' => $this->apiKey,
    //         'Content-Type' => 'application/json'
    //     ])->post("{$this->baseUrl}/orders", [
    //         'shipper_contact_name' => 'Solher Store',
    //         'shipper_contact_phone' => '08123456789',
    //         'origin_postal_code' => config('services.biteship.origin_postal_code'),

    //         // PERBAIKAN DISINI: Panggil kolom langsung
    //         'destination_postal_code' => $transaction->address->postal_code,
    //         'destination_contact_name' => trim($transaction->address->first_name_address . ' ' . $transaction->address->last_name_address),
    //         'destination_contact_phone' => '08123456789', // Ganti dengan nomor telepon user jika ada di tabel user
    //         'destination_address' => $transaction->address->address_location,

    //         'courier_company' => $transaction->courier_company,
    //         'courier_type' => $transaction->courier_type,
    //         'delivery_type' => 'now',
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

    // public function createOrder($transaction)
    // {
    //     $transaction->loadMissing(['address', 'user']);

    //     $response = Http::withHeaders([
    //         'Authorization' => $this->apiKey,
    //         'Content-Type' => 'application/json'
    //     ])->post("{$this->baseUrl}/orders", [
    //         'shipper_contact_name' => 'Solher Store',
    //         'shipper_contact_phone' => '08123456789',
    //         'origin_postal_code' => config('services.biteship.origin_postal_code'),
    //         'destination_postal_code' => $transaction->address->postal_code,
    //         'destination_contact_name' => trim($transaction->address->first_name_address . ' ' . $transaction->address->last_name_address),
    //         // Pastikan format telepon benar (min 10 digit, maks 15 digit angka)
    //         'destination_contact_phone' => '08123456789',
    //         'destination_address' => $transaction->address->address_location,
    //         'courier_company' => $transaction->courier_company,
    //         'courier_type' => $transaction->courier_type,
    //         'delivery_type' => 'now',
    //         'items' => [
    //             [
    //                 'name' => 'Solher Products',
    //                 'value' => (int) $transaction->total_amount,
    //                 'quantity' => 1,
    //                 'weight' => 1000
    //             ]
    //         ]
    //     ]);

    //     $data = $response->json();

    //     // LOGGING PENTING: Agar kita tahu jika Biteship menolak payload kita
    //     if (isset($data['success']) && $data['success'] === false) {
    //         \Log::error('Biteship Create Order Failed: ', $data);
    //     }

    //     return $data;
    // }

    // public function createOrder($transaction)
    // {
    //     // Load relasi
    //     $transaction->loadMissing(['address', 'user']);

    //     $payload = [
    //         'shipper_contact_name' => 'Solher Store',
    //         'shipper_contact_phone' => '08123456789',
    //         'origin_postal_code' => config('services.biteship.origin_postal_code'),

    //         'destination_postal_code' => $transaction->address->postal_code,
    //         'destination_contact_name' => trim($transaction->address->first_name_address . ' ' . $transaction->address->last_name_address),
    //         'destination_contact_phone' => '08123456789', // Harusnya ambil dari data user/address
    //         'destination_address' => $transaction->address->address_location,

    //         'courier_company' => $transaction->courier_company,
    //         'courier_type' => $transaction->courier_type,

    //         'delivery_type' => 'later',

    //         'items' => [
    //             [
    //                 'name' => 'Solher Products',
    //                 'value' => (int) $transaction->total_amount,
    //                 'quantity' => 1,
    //                 'weight' => 1000
    //             ]
    //         ]
    //     ];

    //     $response = Http::withHeaders([
    //         'Authorization' => $this->apiKey,
    //         'Content-Type' => 'application/json'
    //     ])->post("{$this->baseUrl}/orders", $payload);

    //     $data = $response->json();

    //     // [PERBAIKAN] PAKSA TULIS LOG JIKA BITESHIP MENOLAK PAYLOAD
    //     if (isset($data['success']) && $data['success'] === false) {
    //         \Log::channel('stderr')->error('BITESHIP REJECTED ORDER: ' . json_encode($data));
    //         \Log::error('BITESHIP REJECTED ORDER: ' . json_encode($data));
    //     }

    //     return $data;
    // }

    public function createOrder($transaction)
    {
        // Load relasi
        $transaction->loadMissing(['address', 'user']);

        $payload = [
            // [PERBAIKAN] Gunakan awalan 'origin_' sesuai standar Biteship
            'origin_contact_name' => 'Solher Store',
            'origin_contact_phone' => '08123456789',
            'origin_address' => 'Gudang Solher, Jl. Utama No. 1', // Tambahkan alamat gudang/toko Anda
            'origin_postal_code' => config('services.biteship.origin_postal_code'),

            'destination_postal_code' => $transaction->address->postal_code,
            'destination_contact_name' => trim($transaction->address->first_name_address . ' ' . $transaction->address->last_name_address),
            'destination_contact_phone' => '08123456789', // Idealnya ambil dari data user/address jika ada
            'destination_address' => $transaction->address->address_location,

            'courier_company' => $transaction->courier_company,
            'courier_type' => $transaction->courier_type,
            'delivery_type' => 'later',

            'items' => [
                [
                    'name' => 'Solher Products',
                    'value' => (int) $transaction->total_amount,
                    'quantity' => 1,
                    'weight' => 1000
                ]
            ]
        ];

        $response = Http::withHeaders([
            'Authorization' => $this->apiKey,
            'Content-Type' => 'application/json'
        ])->post("{$this->baseUrl}/orders", $payload);

        $data = $response->json();

        // [PERBAIKAN] PAKSA TULIS LOG JIKA BITESHIP MENOLAK PAYLOAD
        if (isset($data['success']) && $data['success'] === false) {
            \Log::channel('stderr')->error('BITESHIP REJECTED ORDER: ' . json_encode($data));
            \Log::error('BITESHIP REJECTED ORDER: ' . json_encode($data));
        }

        return $data;
    }

    public function getTracking($waybillId, $courierCompany)
    {
        $response = Http::withHeaders([
            'Authorization' => $this->apiKey,
        ])->get("{$this->baseUrl}/trackings/{$waybillId}/couriers/{$courierCompany}");

        return $response->json();
    }
}
