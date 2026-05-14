<?php

namespace App\Http\Controllers;
 
use App\Models\UnipinJob;
use App\Services\UnipinService;
use Illuminate\Http\Request;
 
class UnipinController extends Controller
{
    // Session key — pakai email hash supaya lintas device bisa share data
    private function sessionKey(Request $request): string
    {
        $email = $request->input('email') ?? session('unipin_email', '');
        return $email ? 'email_' . md5(strtolower(trim($email))) : 'sid_' . session()->getId();
    }
 
    // ── Halaman utama ────────────────────────────────────────────────
    public function index()
    {
        return view('unipin.manager');
    }
 
    // ── GET /unipin/jobs ─────────────────────────────────────────────
    // Ambil semua job milik user ini
    public function getJobs(Request $request)
    {
        $key  = $this->sessionKey($request);
        $jobs = UnipinJob::forSession($key)
            ->orderBy('id')
            ->get(['id', 'kode', 'status', 'type', 'log', 'amount', 'no_transaksi', 'updated_at']);
 
        return response()->json([
            'jobs'      => $jobs,
            'total'     => $jobs->count(),
            'savedAt'   => $jobs->last()?->updated_at,
        ]);
    }
 
    // ── POST /unipin/jobs/add ────────────────────────────────────────
    // Tambah kode baru ke antrian (skip duplikat otomatis)
    public function addJobs(Request $request)
    {
        $request->validate([
            'kodes' => 'required|array|min:1',
            'email' => 'required|email',
        ]);
 
        $key      = $this->sessionKey($request);
        $tambah   = 0;
        $newJobs  = [];
 
        foreach ($request->kodes as $kode) {
            $kode = trim($kode);
            if (!$kode) continue;
 
            // Selalu insert — boleh kode sama, UniPin yang tentukan hasilnya
            $job = UnipinJob::create([
                'session_key' => $key,
                'kode'        => $kode,
                'status'      => 'pending',
                'log'         => 'Menunggu antrian',
            ]);
 
           $newJobs[] = [
                'id' => $job->id,
                'kode' => $job->kode,
                'status' => $job->status,
                'log' => $job->log,
            ];
            $tambah++;
        }
 
        return response()->json(['success' => true, 'tambah' => $tambah, 'newJobs' => $newJobs]);
    }
 
    // ── PATCH /unipin/jobs/{id} ──────────────────────────────────────
    // Update status satu job
    public function updateJob(Request $request, int $id)
    {
        $job = UnipinJob::findOrFail($id);
        $job->update($request->only(['status', 'log', 'amount', 'no_transaksi', 'type']));
        return response()->json(['success' => true]);
    }
 
    // ── DELETE /unipin/jobs ──────────────────────────────────────────
    // Hapus yang sudah selesai (sukses/gagal), pertahankan pending
    public function clearJobs(Request $request)
    {
        $key     = $this->sessionKey($request);
        $deleted = UnipinJob::forSession($key)
            ->whereIn('status', ['sukses', 'gagal'])
            ->delete();
 
        return response()->json(['success' => true, 'deleted' => $deleted]);
    }
 
    // ── POST /unipin/login ───────────────────────────────────────────
    public function login(Request $request)
    {
        $service = app(UnipinService::class);
        $result  = $service->login($request->email, $request->password);
        return response()->json($result);
    }
 
    // ── POST /unipin/redeem ──────────────────────────────────────────
    public function redeem(Request $request)
    {
        $service = app(UnipinService::class);
        $service->setCredentials($request->email, $request->password);
        $result  = $service->redeemKode($request->kode);
        return response()->json($result);
    }
}