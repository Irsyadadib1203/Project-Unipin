<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\RedeemSession;
use App\Models\RedeemCode;
use App\Services\UnipinService;

class RedeemController extends Controller
{
    public function dashboard()
    {
        return view('dashboard');
    }

    /**
     * API redeem dipanggil dari frontend JS satu per satu.
     *
     * PENTING: Jangan panggil login() di sini.
     * Session proxy sudah dibuat oleh /unipin/login dan disimpan di Laravel session.
     * refreshSessionIfNeeded() di dalam redeemKode() akan mengambilnya otomatis.
     */
    public function apiRedeem(Request $request, UnipinService $unipin)
    {
        $kode     = $request->input('kode');
        $email    = $request->input('email');
        $password = $request->input('password');

        if (!$kode) {
            return response()->json(['success' => false, 'message' => 'Kode kosong'], 422);
        }

        // Set kredensial sebagai fallback kalau session expired dan perlu re-login
        // Tapi TIDAK memanggil login() — pakai session yang sudah ada
        $unipin->setCredentials($email, $password);

        $result = $unipin->redeemKode($kode);

        return response()->json($result);
    }

    public function store(Request $request)
    {
        $kodes = explode("\n", trim($request->kodes));

        $session = RedeemSession::create([
            'unipin_username' => session('unipin_user'),
            'type'            => $request->type,
            'total'           => count($kodes),
        ]);

        foreach ($kodes as $baris) {
            [$kode, $pin] = explode(':', trim($baris));
            RedeemCode::create([
                'session_id' => $session->id,
                'kode'       => $kode,
                'pin'        => $pin,
            ]);
        }

        \Artisan::queue('redeem:start', ['session_id' => $session->id]);

        return response()->json(['session_id' => $session->id]);
    }

    public function status($sessionId)
    {
        $session = RedeemSession::with('codes')->findOrFail($sessionId);
        return response()->json($session);
    }
}