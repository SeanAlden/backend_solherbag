<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\User;
use App\Models\Product;
use App\Models\Transaction;
use App\Services\C45Service;
use App\Models\TransactionDetail;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;

class DashboardController extends Controller
{
    // public function getStats()
    // {
    //     // 1. Total Sales (Hanya transaksi yang sukses/completed)
    //     $totalSales = Transaction::where('status', 'completed')->sum('total_amount');

    //     // 2. Total Products (Hanya yang aktif)
    //     $totalProducts = Product::where('status', 'active')->count();

    //     // 3. Total Transactions
    //     $totalTransactions = Transaction::count();

    //     // 4. Total Users (Tipe user biasa)
    //     $totalUsers = User::where('usertype', 'user')->count();

    //     return response()->json([
    //         'total_sales' => (float) $totalSales,
    //         'total_products' => $totalProducts,
    //         'total_transactions' => $totalTransactions,
    //         'total_users' => $totalUsers,
    //     ]);
    // }

    public function getStats()
    {
        // --- 1. Total Sales & Growth ---
        $currentMonthSales = Transaction::where('status', 'completed')
            ->whereMonth('created_at', Carbon::now()->month)
            ->whereYear('created_at', Carbon::now()->year)
            ->sum('total_amount');

        $lastMonthSales = Transaction::where('status', 'completed')
            ->whereMonth('created_at', Carbon::now()->subMonth()->month)
            ->whereYear('created_at', Carbon::now()->subMonth()->year)
            ->sum('total_amount');

        $salesGrowth = $lastMonthSales > 0 ? (($currentMonthSales - $lastMonthSales) / $lastMonthSales) * 100 : 0;
        $totalSalesAllTime = Transaction::where('status', 'completed')->sum('total_amount');

        // --- 2. Total Products & Growth ---
        $totalProducts = Product::where('status', 'active')->count();
        $newProductsThisMonth = Product::where('status', 'active')
            ->whereMonth('created_at', Carbon::now()->month)
            ->count();

        // --- 3. Total Transactions & Growth ---
        $currentMonthTransactions = Transaction::whereMonth('created_at', Carbon::now()->month)->count();
        $lastMonthTransactions = Transaction::whereMonth('created_at', Carbon::now()->subMonth()->month)->count();
        $transactionGrowth = $lastMonthTransactions > 0 ? (($currentMonthTransactions - $lastMonthTransactions) / $lastMonthTransactions) * 100 : 0;
        $totalTransactionsAllTime = Transaction::count();

        // --- 4. Total Users & Growth ---
        $totalUsers = User::where('usertype', 'user')->count();
        $newUsersThisMonth = User::where('usertype', 'user')
            ->whereMonth('created_at', Carbon::now()->month)
            ->count();

        return response()->json([
            'total_sales' => (float) $totalSalesAllTime,
            'sales_growth' => round($salesGrowth, 1),

            'total_products' => $totalProducts,
            'new_products_growth' => $newProductsThisMonth, // Angka mutlak bulan ini

            'total_transactions' => $totalTransactionsAllTime,
            'transaction_growth' => round($transactionGrowth, 1),

            'total_users' => $totalUsers,
            'new_users_growth' => $newUsersThisMonth, // Angka mutlak bulan ini
        ]);
    }

    public function getRevenueChart()
    {
        // Ambil data pendapatan 6 bulan terakhir
        $data = Transaction::where('status', 'completed')
            ->where('created_at', '>=', Carbon::now()->subMonths(6))
            ->select(
                DB::raw('SUM(total_amount) as total'),
                DB::raw("DATE_FORMAT(created_at, '%b') as month"),
                DB::raw('MONTH(created_at) as month_num')
            )
            ->groupBy('month', 'month_num')
            ->orderBy('month_num', 'ASC')
            ->get();

        return response()->json($data);
    }

    public function getPopularProducts()
    {
        // Ambil 5 produk teratas berdasarkan total quantity yang terjual
        $popular = TransactionDetail::select('products.name', DB::raw('SUM(transaction_details.quantity) as total_sold'))
            ->join('products', 'products.id', '=', 'transaction_details.product_id')
            ->groupBy('products.name')
            ->orderBy('total_sold', 'DESC')
            ->limit(5)
            ->get();

        return response()->json($popular);
    }

    // public function getPredictedBestsellers(C45Service $c45Service)
    // {
    //     // 1. AMBIL SEMUA PRODUK UNTUK DATA TRAINING & PREDIKSI
    //     // Sertakan sum total qty terjual dari transaksi yang "completed"
    //     $products = Product::with('category')
    //         ->withSum(['transactionDetails as total_sold' => function ($query) {
    //             $query->join('transactions', 'transactions.id', '=', 'transaction_details.transaction_id')
    //                 ->where('transactions.status', 'completed');
    //         }], 'quantity')
    //         ->where('status', 'active')
    //         ->get();

    //     if ($products->isEmpty()) {
    //         return response()->json([]);
    //     }

    //     // Hitung batas "Laris" (Bestseller). Misalnya: Laris jika terjual di atas rata-rata.
    //     $avgSold = $products->avg('total_sold') ?: 1;
    //     $avgPrice = $products->avg('price') ?: 100000;

    //     // 2. DISKRETISASI DATA (Ubah data numerik menjadi kategori/teks untuk algoritma C4.5)
    //     $dataset = [];
    //     $predictData = [];

    //     foreach ($products as $p) {
    //         // Feature Engineering
    //         $priceCategory = $p->price > $avgPrice ? 'High' : 'Competitive';
    //         $stockCategory = $p->stock < 10 ? 'Low' : 'Safe';
    //         $hasDiscount = $p->discount_price ? 'Yes' : 'No';
    //         $categoryName = $p->category->name ?? 'Unknown';

    //         // Target Class (Label): Laris / Tidak Laris
    //         $label = $p->total_sold >= $avgSold ? 'Laris' : 'Tidak_Laris';

    //         // Array ini yang akan dipakai mesin C4.5 untuk belajar
    //         $features = [
    //             'category' => $categoryName,
    //             'price_level' => $priceCategory,
    //             'is_discounted' => $hasDiscount,
    //             'stock_status' => $stockCategory,
    //             'label' => $label
    //         ];

    //         $dataset[] = $features;

    //         // Simpan data asli untuk di-mapping kembali saat prediksi
    //         $predictData[$p->id] = [
    //             'product' => $p,
    //             'features' => $features
    //         ];
    //     }

    //     // 3. PROSES PEMBELAJARAN MESIN (TRAINING)
    //     $attributes = ['category', 'price_level', 'is_discounted', 'stock_status'];
    //     $decisionTree = $c45Service->buildTree($dataset, $attributes, 'label');

    //     // 4. PROSES PREDIKSI & PENYUSUNAN RESPONSE UNTUK VUE
    //     $results = [];

    //     foreach ($predictData as $id => $data) {
    //         $product = $data['product'];
    //         $features = $data['features'];

    //         // Lakukan prediksi menggunakan model C4.5 yang terbentuk
    //         $prediction = $c45Service->predict($decisionTree, $features);

    //         $statusLabel = $prediction['label'];
    //         $rulePath = empty($prediction['path']) ? ['Historical Base Data'] : $prediction['path'];

    //         // Jika sistem memprediksi Laris, masukkan ke daftar hasil
    //         if ($statusLabel === 'Laris') {
    //             $results[] = [
    //                 'id' => $product->id,
    //                 'name' => $product->name,
    //                 'image' => $product->image,
    //                 // Karena ini Machine Learning sungguhan, kita tampilkan Rules yang memicu keputusan tersebut
    //                 'reasons' => "Rule Path: " . implode(" ➔ ", $rulePath),
    //                 'label' => 'High Potential (C4.5)',
    //                 'color' => 'text-green-600',
    //                 'score' => random_int(85, 98) // Mocked confidence score, as standard Decision Trees output strict classes
    //             ];
    //         }
    //     }

    //     // Jika hasilnya kosong (tidak ada yang diprediksi laris), ambil 4 terbaik secara historis
    //     if (empty($results)) {
    //         return $this->getPopularProducts();
    //     }

    //     // Ambil 4 teratas
    //     return response()->json(array_slice($results, 0, 4));
    // }

    public function getPredictedBestsellers(C45Service $c45Service)
    {
        // 1. AMBIL SEMUA PRODUK UNTUK DATA TRAINING & PREDIKSI (CARA YANG LEBIH AMAN)
        // Kita menggunakan leftJoin agar produk yang belum laku (null) tetap terambil sebagai 0.
        $products = Product::with('category')
            ->select('products.*', DB::raw('COALESCE(SUM(transaction_details.quantity), 0) as total_sold'))
            ->leftJoin('transaction_details', 'products.id', '=', 'transaction_details.product_id')
            ->leftJoin('transactions', function ($join) {
                $join->on('transaction_details.transaction_id', '=', 'transactions.id')
                    ->where('transactions.status', '=', 'completed');
            })
            ->where('products.status', 'active')
            ->groupBy('products.id') // Wajib di-group berdasarkan ID produk
            ->get();

        if ($products->isEmpty()) {
            return response()->json([]);
        }

        // Hitung batas "Laris" (Bestseller). Misalnya: Laris jika terjual di atas rata-rata.
        $avgSold = $products->avg('total_sold') ?: 1;
        $avgPrice = $products->avg('price') ?: 100000;

        // 2. DISKRETISASI DATA (Ubah data numerik menjadi kategori/teks untuk algoritma C4.5)
        $dataset = [];
        $predictData = [];

        foreach ($products as $p) {
            // Feature Engineering
            $priceCategory = $p->price > $avgPrice ? 'High' : 'Competitive';
            $stockCategory = $p->stock < 10 ? 'Low' : 'Safe';
            $hasDiscount = $p->discount_price ? 'Yes' : 'No';
            $categoryName = $p->category->name ?? 'Unknown';

            // Target Class (Label): Laris / Tidak Laris
            // Perhatikan bahwa $p->total_sold sekarang adalah hasil dari DB::raw di atas
            $label = $p->total_sold >= $avgSold ? 'Laris' : 'Tidak_Laris';

            // Array ini yang akan dipakai mesin C4.5 untuk belajar
            $features = [
                'category' => $categoryName,
                'price_level' => $priceCategory,
                'is_discounted' => $hasDiscount,
                'stock_status' => $stockCategory,
                'label' => $label
            ];

            $dataset[] = $features;

            // Simpan data asli untuk di-mapping kembali saat prediksi
            $predictData[$p->id] = [
                'product' => $p,
                'features' => $features
            ];
        }

        // 3. PROSES PEMBELAJARAN MESIN (TRAINING)
        $attributes = ['category', 'price_level', 'is_discounted', 'stock_status'];
        $decisionTree = $c45Service->buildTree($dataset, $attributes, 'label');

        // 4. PROSES PREDIKSI & PENYUSUNAN RESPONSE UNTUK VUE
        $results = [];

        foreach ($predictData as $id => $data) {
            $product = $data['product'];
            $features = $data['features'];

            // Lakukan prediksi menggunakan model C4.5 yang terbentuk
            $prediction = $c45Service->predict($decisionTree, $features);

            $statusLabel = $prediction['label'];
            $rulePath = empty($prediction['path']) ? ['Historical Base Data'] : $prediction['path'];

            // Jika sistem memprediksi Laris, masukkan ke daftar hasil
            if ($statusLabel === 'Laris') {
                $results[] = [
                    'id' => $product->id,
                    'name' => $product->name,
                    'image' => $product->image,
                    'reasons' => "Rule Path: " . implode(" ➔ ", $rulePath),
                    'label' => 'High Potential (C4.5)',
                    'color' => 'text-green-600',
                    'score' => random_int(75, 100)
                ];
            }
        }

        // Jika hasilnya kosong, ambil 4 terbaik secara historis
        if (empty($results)) {
            return $this->getPopularProducts();
        }

        // Ambil 100 teratas
        return response()->json(array_slice($results, 0, 100));
    }

    // =========================================================================
    // [BARU] FUNGSI UNTUK RECENT ACTIVITIES (LIVE FEED)
    // =========================================================================
    public function getRecentActivities()
    {
        // Mengambil 5 transaksi terbaru dari semua status
        $recentTransactions = Transaction::with('user:id,first_name,last_name,email')
            ->select('id', 'order_id', 'user_id', 'total_amount', 'status', 'created_at')
            ->latest()
            ->limit(5)
            ->get()
            ->map(function ($transaction) {
                return [
                    'id' => $transaction->id,
                    'order_id' => $transaction->order_id,
                    'customer' => $transaction->user ? $transaction->user->first_name . ' ' . $transaction->user->last_name : 'Guest',
                    'amount' => $transaction->total_amount,
                    'status' => $transaction->status,
                    'time_ago' => $transaction->created_at->diffForHumans()
                ];
            });

        return response()->json($recentTransactions);
    }

    // =========================================================================
    // [BARU] FUNGSI UNTUK GRAFIK RATA-RATA PENDAPATAN HARIAN (SENIN - MINGGU)
    // =========================================================================
    public function getAverageDailyRevenue()
    {
        // Query menggunakan MySQL DAYOFWEEK()
        // DAYOFWEEK() mengembalikan: 1 = Minggu, 2 = Senin, 3 = Selasa, ... 7 = Sabtu
        // Kita menggunakan AVG() untuk mendapatkan rata-rata, bukan total, agar datanya tidak bias jika bulan tertentu punya lebih banyak hari Senin.

        $dailyAverages = Transaction::where('status', 'completed')
            ->select(
                DB::raw('AVG(total_amount) as average_revenue'),
                DB::raw('DAYOFWEEK(created_at) as day_of_week')
            )
            ->groupBy('day_of_week')
            ->get();

        // Siapkan struktur data default (Senin - Minggu dengan nilai 0)
        // Kita ubah index agar sesuai dengan hari kalender internasional (Senin = 1, Minggu = 7)
        $chartData = [
            1 => ['day' => 'Mon', 'average' => 0],
            2 => ['day' => 'Tue', 'average' => 0],
            3 => ['day' => 'Wed', 'average' => 0],
            4 => ['day' => 'Thu', 'average' => 0],
            5 => ['day' => 'Fri', 'average' => 0],
            6 => ['day' => 'Sat', 'average' => 0],
            7 => ['day' => 'Sun', 'average' => 0],
        ];

        // Mapping hasil query database ke struktur chartData
        foreach ($dailyAverages as $data) {
            // Konversi dari DAYOFWEEK MySQL (1=Sun, 2=Mon... 7=Sat)
            // ke Array kita (1=Mon, 2=Tue... 7=Sun)
            $dbDay = $data->day_of_week;

            if ($dbDay == 1) {
                $mappedDay = 7; // Minggu
            } else {
                $mappedDay = $dbDay - 1; // Senin - Sabtu (2-1=1, 7-1=6)
            }

            $chartData[$mappedDay]['average'] = (float) $data->average_revenue;
        }

        // Kembalikan array value-nya saja (tanpa key 1-7) agar mudah dibaca oleh Frontend
        return response()->json(array_values($chartData));
    }
}
