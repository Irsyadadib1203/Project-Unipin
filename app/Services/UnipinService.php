<?php

namespace App\Services;

use GuzzleHttp\Client;

class UnipinService
{
    protected Client $proxyClient;
    protected ?string $email    = null;
    protected ?string $password = null;
    protected int $loginTime    = 0;
    protected string $sessionId = '';

    public function __construct()
    {
        $this->proxyClient = new Client([
            'base_uri' => env('UNIPIN_PROXY_URL', 'http://43.133.137.249:8192'),
            'timeout'  => 60,
        ]);
    }

    // ── Session management ──────────────────────────────────────────────────

    protected function createSession(): void
    {
        $this->sessionId = 'unipin_' . uniqid();
        $this->proxyClient->post('/session/create', [
            'json' => ['session_id' => $this->sessionId],
        ]);
    }

    public function destroySession(): void
    {
        if (!$this->sessionId) return;
        try {
            $this->proxyClient->post('/session/destroy', [
                'json' => ['session_id' => $this->sessionId],
            ]);
        } catch (\Exception $e) {}
        $this->sessionId = '';
    }

    // ── Proxy helpers ───────────────────────────────────────────────────────

    protected function proxyGet(string $path): array
    {
        if (!$this->sessionId) $this->createSession();

        $response = $this->proxyClient->post('/request/get', [
            'json' => [
                'session_id' => $this->sessionId,
                'url'        => 'https://www.unipin.com' . $path,
                'headers'    => ['User-Agent' => 'Mozilla/5.0'],
            ],
        ]);

        $data = json_decode((string) $response->getBody(), true);
        \Log::debug('UnipinService GET', ['path' => $path, 'cookies' => $data['cookies'] ?? null]);
        return $data;
    }

    protected function proxyPost(string $path, array $postData): array
    {
        if (!$this->sessionId) $this->createSession();

        $response = $this->proxyClient->post('/request/post', [
            'json' => [
                'session_id' => $this->sessionId,
                'url'        => 'https://www.unipin.com' . $path,
                'post_data'  => $postData,
            ],
        ]);

        return json_decode((string) $response->getBody(), true);
    }

    // ── Public helpers ──────────────────────────────────────────────────────

    public function setCredentials(string $email, string $password): void
    {
        $this->email    = $email;
        $this->password = $password;
    }

    // ── Login ───────────────────────────────────────────────────────────────

    public function login(string $email, string $password): array
    {
        $this->email    = $email;
        $this->password = $password;

        try {
            $this->destroySession();
            $this->createSession();

            $result = $this->proxyGet('/login');
            $html   = $result['response'] ?? '';

            if (!$html) {
                return ['success' => false, 'message' => 'Halaman login kosong'];
            }

            libxml_use_internal_errors(true);
            $dom = new \DOMDocument();
            $dom->loadHTML($html);
            $xpath = new \DOMXPath($dom);

            $form = $xpath->query('//form[@id="signin-form-viaemail"]')->item(0)
                 ?? $xpath->query('//form[@id="signin-form-loginpage"]')->item(0);

            if (!$form) {
                return ['success' => false, 'message' => 'Form login tidak ditemukan'];
            }

            $inputs        = $form->getElementsByTagName('input');
            $formData      = [];
            $emailField    = null;
            $passwordField = null;

            foreach ($inputs as $input) {
                $name  = trim($input->getAttribute('name'));
                $type  = strtolower(trim($input->getAttribute('type')));
                $value = $input->getAttribute('value');
                $id    = $input->getAttribute('id');

                if (!$name) continue;
                if ($type === 'hidden') $formData[$name] = $value;
                if ($type === 'email'    || in_array($id, ['loginEmailSide', 'sign-in-email'])) $emailField    = $name;
                if ($type === 'password' || in_array($id, ['loginPassword', 'signInPassword'])) $passwordField = $name;
            }

            if (!$emailField || !$passwordField) {
                return ['success' => false, 'message' => 'Field login tidak ditemukan'];
            }

            $formData[$emailField]    = $email;
            $formData[$passwordField] = $password;
            $formData['popup']        = '1';

            $this->proxyPost('/login', $formData);

            $checkResult = $this->proxyGet('/profile');
            $checkHtml   = $checkResult['response'] ?? '';

            $isLoggedIn = str_contains($checkHtml, 'Logout')
                       || str_contains($checkHtml, 'Keluar')
                       || str_contains($checkHtml, $email);

            if ($isLoggedIn) {
                $this->loginTime = time();
                session([
                    'unipin_session_id' => $this->sessionId,
                    'unipin_user'       => $email,
                    'unipin_email'      => $email,
                    'unipin_password'   => $password,
                    'unipin_login_time' => $this->loginTime,
                ]);
                return ['success' => true];
            }

            return ['success' => false, 'message' => 'Login gagal, periksa email/password'];

        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ── Session refresh ─────────────────────────────────────────────────────

    protected function refreshSessionIfNeeded(): bool
    {
        if ($this->sessionId && $this->loginTime > 0 && (time() - $this->loginTime) <= 600) {
            return true;
        }

        $savedSessionId = session('unipin_session_id', '');
        $savedLoginTime = (int) session('unipin_login_time', 0);

        if ($savedSessionId && (time() - $savedLoginTime) <= 600) {
            $this->sessionId = $savedSessionId;
            $this->email     = $this->email    ?: session('unipin_email');
            $this->password  = $this->password ?: session('unipin_password');
            $this->loginTime = $savedLoginTime;
            \Log::debug('UnipinService: reuse session', ['session_id' => $this->sessionId, 'age' => time() - $savedLoginTime]);
            return true;
        }

        $email    = $this->email    ?: session('unipin_email');
        $password = $this->password ?: session('unipin_password');

        if (!$email || !$password) {
            \Log::warning('UnipinService: tidak ada kredensial untuk re-login');
            return false;
        }

        \Log::info('UnipinService: session expired, re-login...', ['email' => $email]);
        return $this->login($email, $password)['success'];
    }

    // ── Deteksi kondisi halaman ─────────────────────────────────────────────

    private function isCloudflareChallenge(string $html, int $statusCode): bool
    {
        return str_contains($html, 'Just a moment...')
            || str_contains($html, 'challenges.cloudflare.com')
            || str_contains($html, 'cf_chl')
            || str_contains($html, 'Enable JavaScript and cookies to continue')
            || $statusCode === 403;
    }

    private function isRateLimit(string $html, int $statusCode): bool
    {
        return $statusCode === 429
            || str_contains($html, 'Too Many Requests')
            || str_contains($html, 'terlalu banyak permintaan')
            || str_contains($html, 'rate limit');
    }

    private function isCsrfExpired(string $html, int $statusCode): bool
    {
        return $statusCode === 419
            || str_contains($html, 'PAGE EXPIRED')
            || str_contains($html, 'page has expired');
    }

    private function isMaintenance(string $html): bool
    {
        return str_contains($html, 'under maintenance')
            || str_contains($html, 'sedang dalam perbaikan')
            || str_contains($html, 'maintenance mode')
            || str_contains($html, "We'll be back")
            || str_contains($html, 'akan segera kembali');
    }

    private function isLoginPage(string $html): bool
    {
        return str_contains($html, 'UniPin  Masuk')
            || str_contains($html, 'UniPin Login')
            || str_contains($html, 'signin-form-loginpage')
            || str_contains($html, 'signin-form-viaemail');
    }

    // ── Deteksi error spesifik dari UniPin ──────────────────────────────────

    private function detectSpecificError(string $html): ?string
    {
        $lower = strtolower($html);

        // Serial tidak valid
        if (
            str_contains($html, 'Serial tidak valid')
            || str_contains($html, 'serial not found')
            || str_contains($html, 'serial tidak ditemukan')
            || str_contains($html, 'Voucher tidak ditemukan')
            || str_contains($html, 'voucher not found')
            || str_contains($lower, 'invalid serial')
            || str_contains($lower, 'serial invalid')
        ) {
            return 'Serial tidak valid atau tidak ditemukan';
        }

        // PIN salah — form PIN muncul lagi = UniPin menolak dan minta isi ulang
        if (
            str_contains($html, 'PIN salah')
            || str_contains($lower, 'invalid pin')
            || str_contains($lower, 'wrong pin')
            || str_contains($lower, 'incorrect pin')
            || str_contains($lower, 'pin tidak valid')
            || str_contains($lower, 'pin tidak cocok')
        ) {
            return 'PIN salah atau tidak valid';
        }

        // Voucher sudah dipakai
        if (
            str_contains($html, 'Consumed Voucher')
            || str_contains($html, 'sudah digunakan')
            || str_contains($html, 'already used')
            || str_contains($html, 'already been used')
            || str_contains($lower, 'voucher used')
        ) {
            return 'Voucher sudah pernah digunakan';
        }

        // Voucher kadaluarsa
        if (
            str_contains($html, 'kadaluarsa')
            || str_contains($lower, 'voucher expired')
        ) {
            return 'Voucher sudah kadaluarsa';
        }

        // Voucher tidak valid (generic)
        if (
            str_contains($html, 'Voucher tidak valid')
            || str_contains($lower, 'invalid voucher')
            || str_contains($lower, 'voucher invalid')
        ) {
            return 'Voucher tidak valid';
        }

        // Saldo tidak cukup
        if (str_contains($lower, 'saldo tidak cukup') || str_contains($lower, 'insufficient balance')) {
            return 'Saldo akun tidak cukup';
        }

        return null;
    }

    // ── Redeem ──────────────────────────────────────────────────────────────

    public function redeemKode(string $input, string $type = ''): array
    {
        if (!$this->refreshSessionIfNeeded()) {
            return ['success' => false, 'message' => 'Gagal refresh sesi login'];
        }

        try {
            $parsed = $this->parseKodePin($input);

            if (isset($parsed['error'])) {
                return ['success' => false, 'message' => $parsed['error']];
            }

            $kode = $parsed['kode'];
            $pin  = $parsed['pin'];
            $type = $parsed['type'];

            if ($type === 'unknown') {
                return ['success' => false, 'message' => 'Tipe voucher tidak dikenali (bukan IDMB/UPGC)'];
            }

            $voucherId = match($type) {
                'idmb' => 49,
                'upgc' => 50,
            };

            // ── GET halaman voucher ──────────────────────────────────────────
            $result     = $this->proxyGet("/reload/voucher/{$voucherId}");
            $html       = $result['response']    ?? '';
            $statusCode = $result['status_code'] ?? 200;

            if ($this->isCloudflareChallenge($html, $statusCode)) {
                return ['success' => false, 'message' => 'Diblokir Cloudflare, coba redeem ulang manual', 'kode' => $kode, 'type' => $type];
            }

            if ($this->isMaintenance($html)) {
                return ['success' => false, 'message' => 'UniPin sedang maintenance', 'kode' => $kode, 'type' => $type];
            }

            if ($this->isLoginPage($html)) {
                \Log::warning('UnipinService: session expired saat GET voucher, re-login...');
                $this->loginTime = 0;
                session()->forget(['unipin_session_id', 'unipin_login_time']);

                if (!$this->refreshSessionIfNeeded()) {
                    return ['success' => false, 'message' => 'Sesi expired, gagal re-login', 'kode' => $kode, 'type' => $type];
                }

                $result     = $this->proxyGet("/reload/voucher/{$voucherId}");
                $html       = $result['response']    ?? '';
                $statusCode = $result['status_code'] ?? 200;
            }

            // ── Ambil CSRF token ─────────────────────────────────────────────
            preg_match('/<meta[^>]*name="csrf-token"[^>]*content="([^"]+)"/i', $html, $metaMatch);
            $token = $metaMatch[1] ?? null;
            if (!$token) {
                preg_match('/<input[^>]*name="_token"[^>]*value="([^"]+)"/i', $html, $tokenMatch);
                $token = $tokenMatch[1] ?? null;
            }

            if (!$token) {
                \Log::error('UnipinService: gagal ambil CSRF token', ['snippet' => substr($html, 0, 500)]);
                return ['success' => false, 'message' => 'Gagal ambil CSRF token', 'kode' => $kode, 'type' => $type];
            }

            // ── Validasi PIN ─────────────────────────────────────────────────
            $pin = preg_replace('/[\s\-]/', '', $pin);
            if (strlen($pin) !== 16) {
                return ['success' => false, 'message' => 'PIN harus 16 digit, dapat: ' . strlen($pin), 'kode' => $kode, 'type' => $type];
            }

            // ── POST redeem ──────────────────────────────────────────────────
            $redeemResult = $this->proxyPost("/reload/voucher/{$voucherId}", [
                '_token' => $token,
                'serial' => $kode,
                'pin_1'  => substr($pin, 0, 4),
                'pin_2'  => substr($pin, 4, 4),
                'pin_3'  => substr($pin, 8, 4),
                'pin_4'  => substr($pin, 12, 4),
            ]);

            $html       = $redeemResult['response']    ?? '';
            $finalUrl   = $redeemResult['final_url']   ?? '';
            $statusCode = $redeemResult['status_code'] ?? 0;

            \Log::debug('UnipinService redeemKode response', [
                'kode'      => $kode,
                'type'      => $type,
                'final_url' => $finalUrl,
                'status'    => $statusCode,
                'snippet'   => substr($html, 0, 2000),
            ]);

            // ── Kondisi khusus ────────────────────────────────────────────────
            if ($this->isCloudflareChallenge($html, $statusCode)) {
                sleep(3);
                return ['success' => false, 'message' => 'Diblokir Cloudflare setelah POST', 'kode' => $kode, 'type' => $type];
            }

            if ($this->isRateLimit($html, $statusCode)) {
                sleep(10);
                return ['success' => false, 'message' => 'Rate limit — tunggu sebentar lalu coba lagi', 'kode' => $kode, 'type' => $type];
            }

            // CSRF expired → retry sekali dengan token baru
            if ($this->isCsrfExpired($html, $statusCode)) {
                \Log::warning('UnipinService: CSRF expired, retry...', ['kode' => $kode]);
                $retry = $this->proxyGet("/reload/voucher/{$voucherId}");
                preg_match('/<meta[^>]*name="csrf-token"[^>]*content="([^"]+)"/i', $retry['response'] ?? '', $rm);
                $newToken = $rm[1] ?? null;
                if (!$newToken) {
                    preg_match('/<input[^>]*name="_token"[^>]*value="([^"]+)"/i', $retry['response'] ?? '', $ri);
                    $newToken = $ri[1] ?? null;
                }
                if ($newToken) {
                    $redeemResult = $this->proxyPost("/reload/voucher/{$voucherId}", [
                        '_token' => $newToken, 'serial' => $kode,
                        'pin_1'  => substr($pin, 0, 4), 'pin_2' => substr($pin, 4, 4),
                        'pin_3'  => substr($pin, 8, 4), 'pin_4' => substr($pin, 12, 4),
                    ]);
                    $html       = $redeemResult['response']    ?? '';
                    $finalUrl   = $redeemResult['final_url']   ?? '';
                    $statusCode = $redeemResult['status_code'] ?? 0;
                } else {
                    sleep(3);
                    return ['success' => false, 'message' => 'CSRF expired dan gagal ambil token baru', 'kode' => $kode, 'type' => $type];
                }
            }

            // Session expired saat POST → re-login dan retry
            if ($this->isLoginPage($html)) {
                \Log::warning('UnipinService: session expired saat POST, re-login...', ['kode' => $kode]);
                $this->loginTime = 0;
                session()->forget(['unipin_session_id', 'unipin_login_time']);

                if (!$this->refreshSessionIfNeeded()) {
                    sleep(3);
                    return ['success' => false, 'message' => 'Sesi expired saat redeem, gagal re-login', 'kode' => $kode, 'type' => $type];
                }

                $retry = $this->proxyGet("/reload/voucher/{$voucherId}");
                preg_match('/<meta[^>]*name="csrf-token"[^>]*content="([^"]+)"/i', $retry['response'] ?? '', $rm);
                $newToken = $rm[1] ?? null;
                if (!$newToken) {
                    preg_match('/<input[^>]*name="_token"[^>]*value="([^"]+)"/i', $retry['response'] ?? '', $ri);
                    $newToken = $ri[1] ?? null;
                }
                if (!$newToken) {
                    sleep(3);
                    return ['success' => false, 'message' => 'Gagal ambil token baru setelah re-login', 'kode' => $kode, 'type' => $type];
                }
                $redeemResult = $this->proxyPost("/reload/voucher/{$voucherId}", [
                    '_token' => $newToken, 'serial' => $kode,
                    'pin_1'  => substr($pin, 0, 4), 'pin_2' => substr($pin, 4, 4),
                    'pin_3'  => substr($pin, 8, 4), 'pin_4' => substr($pin, 12, 4),
                ]);
                $html       = $redeemResult['response']    ?? '';
                $finalUrl   = $redeemResult['final_url']   ?? '';
                $statusCode = $redeemResult['status_code'] ?? 0;
            }

            if ($this->isMaintenance($html)) {
                sleep(3);
                return ['success' => false, 'message' => 'UniPin sedang maintenance', 'kode' => $kode, 'type' => $type];
            }

            // ── DETEKSI SUKSES ───────────────────────────────────────────────
            $isSuccessByUrl   = str_contains($finalUrl, '/reload/result/');
            $isSuccessByClass = str_contains($html, 'payment-success-badge')
                             || str_contains($html, 'checkout-amount');
            $isSuccessByText  = str_contains($html, 'Transaksi berhasil')
                             || str_contains($html, 'payment-success-check.svg');

            $isSuccess = $isSuccessByUrl || $isSuccessByClass || $isSuccessByText;

            // ── DETEKSI GAGAL SPESIFIK ───────────────────────────────────────
            $specificError = $this->detectSpecificError($html);
            $isFailByClass = str_contains($html, 'payment-failed-badge')
                          || str_contains($html, 'checkout-failed');
            $isFailed = $specificError !== null || $isFailByClass;

            $message = $this->extractResultMessage($html);
            $amount  = $this->extractAmount($html);
            $noTrx   = $this->extractNoTransaksi($html);

            \Log::info('UnipinService deteksi result', [
                'kode'           => $kode,
                'is_success_url' => $isSuccessByUrl,
                'is_success_cls' => $isSuccessByClass,
                'is_success_txt' => $isSuccessByText,
                'specific_error' => $specificError,
                'is_fail_class'  => $isFailByClass,
                'final_url'      => $finalUrl,
                'status'         => $statusCode,
            ]);

            sleep(3);

            if ($isSuccess && !$isFailed) {
                return [
                    'success'      => true,
                    'message'      => $message ?: 'Redeem berhasil',
                    'amount'       => $amount,
                    'no_transaksi' => $noTrx,
                    'kode'         => $kode,
                    'type'         => $type,
                ];
            }

            if ($isFailed) {
                $failMessage = $specificError ?: ($message ?: 'Redeem gagal');
                \Log::warning('UnipinService: redeem gagal', [
                    'kode'           => $kode,
                    'specific_error' => $specificError,
                    'final_url'      => $finalUrl,
                ]);
                return ['success' => false, 'message' => $failMessage, 'kode' => $kode, 'type' => $type];
            }

            // Tidak ada sinyal jelas — tapi kalau final_url ada /result/ → anggap sukses
            if (str_contains($finalUrl, '/reload/result/')) {
                \Log::info('UnipinService: final_url result tapi keyword tidak match, anggap sukses', ['kode' => $kode]);
                return [
                    'success'      => true,
                    'message'      => 'Redeem berhasil',
                    'amount'       => $amount,
                    'no_transaksi' => $noTrx,
                    'kode'         => $kode,
                    'type'         => $type,
                ];
            }

            \Log::warning('UnipinService: response tidak dikenali', [
                'kode'      => $kode,
                'final_url' => $finalUrl,
                'status'    => $statusCode,
                'snippet'   => substr($html, 0, 3000),
            ]);

            return [
                'success'   => false,
                'message'   => 'Response tidak dikenali (status: ' . $statusCode . ', url: ' . ($finalUrl ?: 'tidak ada') . ')',
                'final_url' => $finalUrl,
                'kode'      => $kode,
                'type'      => $type,
            ];

        } catch (\Exception $e) {
            sleep(3);
            return ['success' => false, 'message' => 'Exception: ' . $e->getMessage()];
        }
    }

    // ── Result extractors ───────────────────────────────────────────────────

    private function extractResultMessage(string $html): string
    {
        $patterns = [
            '/<span[^>]*class="[^"]*text-success[^"]*"[^>]*>(.*?)<\/span>/is',
            '/<div[^>]*class="[^"]*checkout-payment-name[^"]*"[^>]*>(.*?)<\/div>/is',
            '/<div[^>]*class="[^"]*alert[^"]*"[^>]*>(.*?)<\/div>/is',
            '/<div[^>]*class="[^"]*toast[^"]*"[^>]*>(.*?)<\/div>/is',
            '/<p[^>]*class="[^"]*alert[^"]*"[^>]*>(.*?)<\/p>/is',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $m)) {
                $text = trim(strip_tags($m[1]));
                if ($text) return $text;
            }
        }

        return '';
    }

    private function extractAmount(string $html): string
    {
        if (preg_match('/<div[^>]*class="[^"]*checkout-amount[^"]*"[^>]*>(.*?)<\/div>/is', $html, $m)) {
            return trim(strip_tags($m[1]));
        }
        return '';
    }

    private function extractNoTransaksi(string $html): string
    {
        if (preg_match('/No\.\s*Transaksi.*?([a-f0-9\-]{30,})/is', $html, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})/i', $html, $m)) {
            return $m[1];
        }
        return '';
    }

    // ── Parsing ─────────────────────────────────────────────────────────────

    public function parseKodePin(string $input): array
    {
        $input      = trim($input);
        $formatKode = fn($k) => strtoupper(preg_replace('/[^A-Z0-9]/', '', trim($k)));

        if (str_contains($input, '#')) {
            [$kode, $pin] = explode('#', $input, 2);
            $kode = $formatKode($kode);
            $pin  = preg_replace('/[\s\-]/', '', $pin);
            return ['kode' => $kode, 'kode_raw' => $kode, 'pin' => $pin, 'type' => $this->detectType($kode)];
        }

        if (str_contains($input, '|')) {
            [$kode, $pin] = explode('|', $input, 2);
            $kode = $formatKode($kode);
            $pin  = preg_replace('/[\s\-]/', '', $pin);
            return ['kode' => $kode, 'kode_raw' => $kode, 'pin' => $pin, 'type' => $this->detectType($kode)];
        }

        $clean = preg_replace('/[\s\-]/', '', $input);

        if (strlen($clean) > 16 && ctype_digit(substr($clean, -16))) {
            $pin  = substr($clean, -16);
            $kode = $formatKode(substr($clean, 0, strlen($clean) - 16));
            return ['kode' => $kode, 'kode_raw' => $kode, 'pin' => $pin, 'type' => $this->detectType($kode)];
        }

        $digits = preg_replace('/[^0-9]/', '', $clean);
        if (strlen($digits) >= 16) {
            $pin  = substr($digits, -16);
            $kode = $formatKode(substr($clean, 0, strlen($clean) - 16));
            return ['kode' => $kode, 'kode_raw' => $input, 'pin' => $pin, 'type' => $this->detectType($kode)];
        }

        return ['kode' => null, 'pin' => null, 'type' => null, 'error' => 'Format tidak dikenali'];
    }

    protected function detectType(string $kode): string
    {
        $u = strtoupper(preg_replace('/[\s\-]/', '', $kode));
        if (str_starts_with($u, 'IDMB')) return 'idmb';
        if (str_starts_with($u, 'UPGC') || str_starts_with($u, 'UPG')) return 'upgc';
        if (str_contains(strtolower($kode), 'idmb')) return 'idmb';
        if (str_contains(strtolower($kode), 'upgc')) return 'upgc';
        return 'unknown';
    }

    // ── Debug helpers ───────────────────────────────────────────────────────

    public function debugVoucher(int $voucherId): array
    {
        $result = $this->proxyGet("/reload/voucher/{$voucherId}");
        $html   = $result['response'] ?? '';

        preg_match('/<title>(.*?)<\/title>/i', $html, $titleMatch);
        preg_match('/<input[^>]*name="_token"[^>]*value="([^"]+)"/i', $html, $tokenMatch);

        return [
            'has_token'    => isset($tokenMatch[1]),
            'token_value'  => $tokenMatch[1] ?? null,
            'title'        => $titleMatch[1] ?? '',
            'is_logged'    => str_contains($html, 'Logout') || str_contains($html, 'Keluar'),
            'html_snippet' => substr($html, 0, 2000),
        ];
    }

    public function debugRedeem(string $input): array
    {
        if (!$this->refreshSessionIfNeeded()) {
            return ['error' => 'Gagal refresh session'];
        }

        $parsed = $this->parseKodePin($input);
        if (isset($parsed['error'])) return ['error' => $parsed['error']];

        $voucherId = match($parsed['type']) {
            'idmb'  => 49,
            'upgc'  => 50,
            default => null,
        };

        if (!$voucherId) return ['error' => 'Tipe tidak dikenali: ' . $parsed['type']];

        $pageResult = $this->proxyGet("/reload/voucher/{$voucherId}");
        $html       = $pageResult['response'] ?? '';

        preg_match('/<meta[^>]*name="csrf-token"[^>]*content="([^"]+)"/i', $html, $metaMatch);
        $token = $metaMatch[1] ?? null;
        if (!$token) {
            preg_match('/<input[^>]*name="_token"[^>]*value="([^"]+)"/i', $html, $tokenMatch);
            $token = $tokenMatch[1] ?? null;
        }

        $pin = preg_replace('/[\s\-]/', '', $parsed['pin']);

        $redeemResult = $this->proxyPost("/reload/voucher/{$voucherId}", [
            '_token' => $token, 'serial' => $parsed['kode'],
            'pin_1'  => substr($pin, 0, 4), 'pin_2' => substr($pin, 4, 4),
            'pin_3'  => substr($pin, 8, 4), 'pin_4' => substr($pin, 12, 4),
        ]);

        $resHtml    = $redeemResult['response']    ?? '';
        $statusCode = $redeemResult['status_code'] ?? 0;

        return [
            'parsed'            => $parsed,
            'token'             => $token,
            'final_url'         => $redeemResult['final_url'] ?? '(tidak ada)',
            'status_code'       => $statusCode,
            'specific_error'    => $this->detectSpecificError($resHtml),
            'is_cloudflare'     => $this->isCloudflareChallenge($resHtml, $statusCode),
            'is_rate_limit'     => $this->isRateLimit($resHtml, $statusCode),
            'is_csrf_expired'   => $this->isCsrfExpired($resHtml, $statusCode),
            'is_maintenance'    => $this->isMaintenance($resHtml),
            'is_login_page'     => $this->isLoginPage($resHtml),
            'has_success_badge' => str_contains($resHtml, 'payment-success-badge'),
            'has_success_text'  => str_contains($resHtml, 'Transaksi berhasil'),
            'has_checkout_amt'  => str_contains($resHtml, 'checkout-amount'),
            'amount'            => $this->extractAmount($resHtml),
            'message'           => $this->extractResultMessage($resHtml),
            'html_snippet'      => substr($resHtml, 0, 3000),
        ];
    }
}