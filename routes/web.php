<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\RedeemController;

Route::get('/', function () {
    return view('unipin');
});

// Web auth
Route::get('/login',  [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout',[AuthController::class, 'logout'])->name('logout');

// ── API untuk frontend JS ──
Route::post('/unipin/login',  [AuthController::class, 'apiLogin']);
Route::post('/unipin/redeem', [RedeemController::class, 'apiRedeem']);

// Dashboard
Route::get('/dashboard', [RedeemController::class, 'dashboard'])->name('dashboard');
Route::post('/redeem', [RedeemController::class, 'store']);
Route::get('/redeem/{id}/status', [RedeemController::class, 'status']);

// routes/web.php - sementara
Route::get('/test-redeem', function () {
    $service = new \App\Services\UnipinService();
    
    // Login dulu
    $service->login('irsyadadib1234@gmail.com', 'Irsyad1203');
    
    // Cek apa yang dikembalikan halaman voucher
    $result = $service->debugVoucher(49);
    
    return response()->json([
        'has_token'   => $result['has_token'],
        'title'       => $result['title'],
        'is_logged'   => $result['is_logged'],
        'html_snippet'=> $result['html_snippet'],
    ]);
});