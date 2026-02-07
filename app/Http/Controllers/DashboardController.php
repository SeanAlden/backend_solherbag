<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\User;
use App\Models\Product;
use App\Models\Transaction;
use Illuminate\Http\Request;
use App\Models\TransactionDetail;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;

class DashboardController extends Controller
{
    public function getStats()
    {
        // 1. Total Sales (Hanya transaksi yang sukses/completed)
        $totalSales = Transaction::where('status', 'completed')->sum('total_amount');

        // 2. Total Products (Hanya yang aktif)
        $totalProducts = Product::where('status', 'active')->count();

        // 3. Total Transactions
        $totalTransactions = Transaction::count();

        // 4. Total Users (Tipe user biasa)
        $totalUsers = User::where('usertype', 'user')->count();

        return response()->json([
            'total_sales' => (float) $totalSales,
            'total_products' => $totalProducts,
            'total_transactions' => $totalTransactions,
            'total_users' => $totalUsers,
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
}
