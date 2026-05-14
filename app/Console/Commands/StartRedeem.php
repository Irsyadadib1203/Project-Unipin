<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use App\Models\RedeemSession;
use App\Services\UnipinService;

class StartRedeem extends Command
{
    protected $signature   = 'redeem:start {session_id}';
    protected $description = 'Proses redeem kode Unipin';

    public function handle(UnipinService $unipin)
    {
        $session = RedeemSession::with('codes')->find($this->argument('session_id'));

        foreach ($session->codes as $code) {
            $result = $unipin->redeemKode($code->kode, $code->pin, $session->type);

            $code->update([
                'status'  => $result['success'] ? 'success' : 'failed',
                'message' => $result['message'],
            ]);

            $result['success']
                ? $session->increment('success')
                : $session->increment('failed');

            sleep(5); // jeda 5 detik
        }

        $session->update(['status' => 'done']);
    }
}
