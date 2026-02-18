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
    // public function getRates($destinationPostalCode, $weight = 1000)
    // {
    //     $response = Http::withHeaders([
    //         'Authorization' => $this->apiKey,
    //         'Content-Type' => 'application/json'
    //     ])->post("{$this->baseUrl}/rates/couriers", [
    //         'origin_postal_code' => config('services.biteship.origin_postal_code'),
    //         'destination_postal_code' => $destinationPostalCode,
    //         'couriers' => 'jne,sicepat,jnt,anteraja,grab,gojek,paxel,ninja', // Tentukan kurir yang aktif
    //         'items' => [
    //             ['weight' => $weight] // Default 1kg, bisa dibuat dinamis jika tabel product punya kolom weight
    //         ]
    //     ]);

    //     return $response->json();
    // }

    public function getRates($address, $weight = 1000)
    {
        $payload = [
            'origin_postal_code' => config('services.biteship.origin_postal_code'),

            // Masukkan data tujuan lengkap
            'destination_postal_code' => $address->postal_code,

            'origin_latitude' => '-7.25706',
            'origin_longitude' => '112.74549',

            // [TAMBAHAN PENTING] Kirim koordinat agar akurat & muncul di respon
            'destination_latitude' => $address->latitude,
            'destination_longitude' => $address->longitude,

            'couriers' => 'jne,sicepat,jnt,anteraja,grab,gojek,paxel,ninja',
            'items' => [
                ['weight' => $weight]
            ]
        ];

        $response = Http::withHeaders([
            'Authorization' => $this->apiKey,
            'Content-Type' => 'application/json'
        ])->post("{$this->baseUrl}/rates/couriers", $payload);

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

    // public function createOrder($transaction)
    // {
    //     // Load relasi
    //     $transaction->loadMissing(['address', 'user']);

    //     $payload = [
    //         // [PERBAIKAN] Gunakan awalan 'origin_' sesuai standar Biteship
    //         'origin_contact_name' => 'Solher Store',
    //         'origin_contact_phone' => '08123456789',
    //         'origin_address' => 'Gudang Solher, Jl. Utama No. 1', // Tambahkan alamat gudang/toko Anda
    //         'origin_postal_code' => config('services.biteship.origin_postal_code'),

    //         'destination_postal_code' => $transaction->address->postal_code,
    //         'destination_contact_name' => trim($transaction->address->first_name_address . ' ' . $transaction->address->last_name_address),
    //         'destination_contact_phone' => '08123456789', // Idealnya ambil dari data user/address jika ada
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

    // public function createOrder($transaction)
    // {
    //     // Load relasi
    //     $transaction->loadMissing(['address', 'user']);

    //     // Set zona waktu agar sesuai dengan Indonesia (WIB)
    //     date_default_timezone_set('Asia/Jakarta');

    //     $payload = [
    //         'origin_contact_name' => 'Solher Store',
    //         'origin_contact_phone' => '08123456789',
    //         'origin_address' => 'Gudang Solher, Jl. Utama No. 1',
    //         'origin_postal_code' => config('services.biteship.origin_postal_code'),

    //         'destination_postal_code' => $transaction->address->postal_code,
    //         'destination_contact_name' => trim($transaction->address->first_name_address . ' ' . $transaction->address->last_name_address),
    //         'destination_contact_phone' => '08123456789', // Ingat, idealnya ini dari nomor HP pembeli asli
    //         'destination_address' => $transaction->address->address_location,

    //         'courier_company' => $transaction->courier_company,
    //         'courier_type' => $transaction->courier_type,

    //         'delivery_type' => 'later',

    //         // [TAMBAHAN BARU] Jadwal pengiriman/pickup (Hari ini, 1 jam dari sekarang)
    //         'delivery_date' => date('Y-m-d'),
    //         'delivery_time' => date('H:i', strtotime('+1 hour')),

    //         'items' => [
    //             [
    //                 'name' => 'Solher Products',
    //                 'value' => (int) $transaction->total_amount,
    //                 'quantity' => 1,
    //                 'weight' => 1000 // Satuan dalam gram (1000 = 1kg)
    //             ]
    //         ]
    //     ];

    //     $response = Http::withHeaders([
    //         'Authorization' => $this->apiKey,
    //         'Content-Type' => 'application/json'
    //     ])->post("{$this->baseUrl}/orders", $payload);

    //     $data = $response->json();

    //     // PAKSA TULIS LOG JIKA BITESHIP MENOLAK PAYLOAD
    //     if (isset($data['success']) && $data['success'] === false) {
    //         \Log::channel('stderr')->error('BITESHIP REJECTED ORDER: ' . json_encode($data));
    //         \Log::error('BITESHIP REJECTED ORDER: ' . json_encode($data));
    //     }

    //     return $data;
    // }

    // public function createOrder($transaction)
    // {
    //     // Load relasi address, user, dan details (untuk ambil quantity)
    //     $transaction->loadMissing(['address', 'user', 'details']);

    //     date_default_timezone_set('Asia/Jakarta');

    //     // [PERBAIKAN] Menghitung total quantity item dari relasi details
    //     $totalQuantity = $transaction->details->sum('quantity');

    //     $payload = [
    //         // INFO PENGIRIM (Tetap statis karena ini toko Anda)
    //         'origin_contact_name' => 'Solher Store',
    //         'origin_contact_phone' => '08123456789',
    //         'origin_address' => 'Gudang Solher, Jl. Utama No. 1',
    //         'origin_postal_code' => config('services.biteship.origin_postal_code'),

    //         // INFO PENERIMA (Dinamis dari relasi)
    //         'destination_postal_code' => $transaction->address->postal_code,
    //         'destination_contact_name' => trim($transaction->address->first_name_address . ' ' . $transaction->address->last_name_address),

    //         // Mengambil phone dari tabel users. Jika null, gunakan fallback agar Biteship tidak menolak pesanan.
    //         'destination_contact_phone' => $transaction->user->phone ?? '08123456789',
    //         'destination_address' => $transaction->address->address_location,

    //         'destination_coordinate' => [
    //             'latitude' => floatval($transaction->address->latitude),
    //             'longitude' => floatval($transaction->address->longitude),
    //         ],

    //         // INFO KURIR & JADWAL (Dari input user di Frontend)
    //         'courier_company' => $transaction->courier_company,
    //         'courier_type' => $transaction->courier_type,
    //         'delivery_type' => $transaction->delivery_type ?? 'later',
    //         'delivery_date' => $transaction->delivery_date ?? date('Y-m-d'),
    //         'delivery_time' => $transaction->delivery_time ?? date('H:i', strtotime('+1 hour')),

    //         // ITEM DETAILS
    //         'items' => [
    //             [
    //                 'name' => 'Solher Products',
    //                 'value' => (int) $transaction->total_amount,
    //                 'quantity' => (int) $totalQuantity, // Nilai dinamis
    //                 'weight' => 1000 * $totalQuantity   // Asumsi 1 barang = 1000 gram (1kg)
    //             ]
    //         ]
    //     ];

    //     $response = Http::withHeaders([
    //         'Authorization' => $this->apiKey,
    //         'Content-Type' => 'application/json'
    //     ])->post("{$this->baseUrl}/orders", $payload);

    //     $data = $response->json();

    //     if (isset($data['success']) && $data['success'] === false) {
    //         \Log::channel('stderr')->error('BITESHIP REJECTED ORDER: ' . json_encode($data));
    //         \Log::error('BITESHIP REJECTED ORDER: ' . json_encode($data));
    //     }

    //     return $data;
    // }

    public function createOrder($transaction)
    {
        // Load relasi
        $transaction->loadMissing(['address', 'user', 'details']);

        date_default_timezone_set('Asia/Jakarta');

        // Hitung total quantity
        $totalQuantity = $transaction->details->sum('quantity');

        // $payload = [
        //     // INFO PENGIRIM
        //     'origin_contact_name' => 'Solher Store',
        //     'origin_contact_phone' => '08123456789',
        //     'origin_address' => 'Gudang Solher, Jl. Utama No. 1',
        //     'origin_postal_code' => config('services.biteship.origin_postal_code'),

        //     // [PERBAIKAN PENTING] Tambahkan Koordinat Asal (Gudang) untuk Kurir Instan (Grab/Gojek)
        //     // Ganti koordinat ini dengan koordinat asli gudang Anda!
        //     'origin_coordinate' => [
        //         'latitude' => -7.25706,  // Contoh: Latitude Surabaya Pusat
        //         'longitude' => 112.74549 // Contoh: Longitude Surabaya Pusat
        //     ],

        //     // INFO PENERIMA
        //     'destination_postal_code' => $transaction->address->postal_code,
        //     'destination_contact_name' => trim($transaction->address->first_name_address . ' ' . $transaction->address->last_name_address),
        //     'destination_contact_phone' => $transaction->user->phone ?? '08123456789',
        //     'destination_address' => $transaction->address->address_location,

        //     // Koordinat Penerima (Wajib untuk Grab/Gojek)
        //     'destination_coordinate' => [
        //         'latitude' => floatval($transaction->address->latitude),
        //         'longitude' => floatval($transaction->address->longitude),
        //     ],

        //     // INFO KURIR & JADWAL
        //     'courier_company' => $transaction->courier_company,
        //     'courier_type' => $transaction->courier_type,
        //     'delivery_type' => $transaction->delivery_type ?? 'later',
        //     'delivery_date' => $transaction->delivery_date ?? date('Y-m-d'),
        //     'delivery_time' => $transaction->delivery_time ?? date('H:i', strtotime('+1 hour')),

        //     // ITEM DETAILS
        //     'items' => [
        //         [
        //             'name' => 'Solher Products',
        //             'value' => (int) $transaction->total_amount,
        //             'quantity' => (int) $totalQuantity,
        //             'weight' => 1000 * $totalQuantity
        //         ]
        //     ]
        // ];

        $payload = [
            // INFO PENGIRIM
            'origin_contact_name' => 'Solher Store',
            'origin_contact_phone' => '08123456789',
            'origin_address' => 'Gudang Solher, Jl. Utama No. 1',
            'origin_postal_code' => config('services.biteship.origin_postal_code'),

            // [FIX 1] Format Object (Standar Dokumentasi)
            'origin_coordinate' => [
                'latitude' => -7.25706,
                'longitude' => 112.74549
            ],

            // [FIX 2] Format Flat (Legacy Support / Jaga-jaga)
            // Tambahkan ini agar sistem Biteship yang membaca format lama tetap bisa mendeteksi lokasi
            'origin_latitude' => -7.25706,
            'origin_longitude' => 112.74549,

            // INFO PENERIMA
            'destination_postal_code' => $transaction->address->postal_code,
            'destination_contact_name' => trim($transaction->address->first_name_address . ' ' . $transaction->address->last_name_address),
            'destination_contact_phone' => $transaction->user->phone ?? '08123456789',
            'destination_address' => $transaction->address->address_location,

            // Koordinat Penerima (Object)
            'destination_coordinate' => [
                'latitude' => floatval($transaction->address->latitude),
                'longitude' => floatval($transaction->address->longitude),
            ],

            // Koordinat Penerima (Flat - Jaga-jaga)
            'destination_latitude' => floatval($transaction->address->latitude),
            'destination_longitude' => floatval($transaction->address->longitude),

            // INFO KURIR
            'courier_company' => $transaction->courier_company,
            'courier_type' => $transaction->courier_type,
            'delivery_type' => $transaction->delivery_type ?? 'later',
            'delivery_date' => $transaction->delivery_date ?? date('Y-m-d'),
            'delivery_time' => $transaction->delivery_time ?? date('H:i', strtotime('+1 hour')),

            'items' => [
                [
                    'name' => 'Solher Products',
                    'value' => (int) $transaction->total_amount,
                    'quantity' => (int) $totalQuantity,
                    'weight' => 1000 * $totalQuantity
                ]
            ]
        ];

        // [DEBUGGING] Log Payload sebelum dikirim untuk memastikan data benar
        \Log::channel('stderr')->info('BITESHIP PAYLOAD:', $payload);

        $response = Http::withHeaders([
            'Authorization' => $this->apiKey,
            'Content-Type' => 'application/json'
        ])->post("{$this->baseUrl}/orders", $payload);

        $response = Http::withHeaders([
            'Authorization' => $this->apiKey,
            'Content-Type' => 'application/json'
        ])->post("{$this->baseUrl}/orders", $payload);

        $data = $response->json();

        // Log error jika gagal
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
