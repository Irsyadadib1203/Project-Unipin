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
        if (!$this->sessionId) {
            $this->createSession();
        }

        $response = $this->proxyClient->post('/request/get', [
            'json' => [
                'session_id' => $this->sessionId,
                'url'        => 'https://www.unipin.com' . $path,
                'headers'    => [
                    'User-Agent' => 'Mozilla/5.0',
                ],
            ],
        ]);

        $data = json_decode((string) $response->getBody(), true);

        \Log::debug('UnipinService GET', [
            'path'    => $path,
            'cookies' => $data['cookies'] ?? null,
        ]);

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

                if ($type === 'hidden') {
                    $formData[$name] = $value;
                }

                if ($type === 'email' || in_array($id, ['loginEmailSide', 'sign-in-email'])) {
                    $emailField = $name;
                }

                if ($type === 'password' || in_array($id, ['loginPassword', 'signInPassword'])) {
                    $passwordField = $name;
                }
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
        if ($this->sessionId && $this->loginTime > 0) {
            if ((time() - $this->loginTime) <= 600) {
                return true;
            }
        }

        $savedSessionId = session('unipin_session_id', '');
        $savedLoginTime = (int) session('unipin_login_time', 0);

        if ($savedSessionId && (time() - $savedLoginTime) <= 600) {
            $this->sessionId = $savedSessionId;
            $this->email     = $this->email     ?: session('unipin_email');
            $this->password  = $this->password  ?: session('unipin_password');
            $this->loginTime = $savedLoginTime;

            \Log::debug('UnipinService: reuse session dari Laravel session', [
                'session_id' => $this->sessionId,
                'age'        => time() - $savedLoginTime,
            ]);

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

            // GET halaman voucher
            $result = $this->proxyGet("/reload/voucher/{$voucherId}");
            $html   = $result['response'] ?? '';

            // Cek redirect ke halaman login
            if (str_contains($html, 'UniPin  Masuk') || str_contains($html, 'UniPin Login')) {
                \Log::warning('UnipinService: session expired saat GET voucher, re-login...');
                $this->loginTime = 0;
                session()->forget(['unipin_session_id', 'unipin_login_time']);

                if (!$this->refreshSessionIfNeeded()) {
                    return ['success' => false, 'message' => 'Sesi expired, gagal re-login'];
                }

                $result = $this->proxyGet("/reload/voucher/{$voucherId}");
                $html   = $result['response'] ?? '';
            }

            // Ambil CSRF token
            preg_match('/<meta[^>]*name="csrf-token"[^>]*content="([^"]+)"/i', $html, $metaMatch);
            $token = $metaMatch[1] ?? null;

            if (!$token) {
                preg_match('/<input[^>]*name="_token"[^>]*value="([^"]+)"/i', $html, $tokenMatch);
                $token = $tokenMatch[1] ?? null;
            }

            if (!$token) {
                \Log::error('UnipinService: gagal ambil CSRF token', ['html_snippet' => substr($html, 0, 500)]);
                return ['success' => false, 'message' => 'Gagal ambil CSRF token redeem'];
            }

            // Validasi PIN 16 digit
            $pin = preg_replace('/[\s\-]/', '', $pin);
            if (strlen($pin) !== 16) {
                return ['success' => false, 'message' => 'PIN harus 16 digit, dapat: ' . strlen($pin)];
            }

            // POST redeem
            $redeemResult = $this->proxyPost("/reload/voucher/{$voucherId}", [
                '_token' => $token,
                'serial' => $kode,
                'pin_1'  => substr($pin, 0, 4),
                'pin_2'  => substr($pin, 4, 4),
                'pin_3'  => substr($pin, 8, 4),
                'pin_4'  => substr($pin, 12, 4),
            ]);

            $html        = $redeemResult['response']    ?? '';
            $finalUrl    = $redeemResult['final_url']   ?? '';  // URL akhir setelah redirect
            $statusCode  = $redeemResult['status_code'] ?? 0;

            \Log::debug('UnipinService redeemKode response', [
                'kode'       => $kode,
                'type'       => $type,
                'final_url'  => $finalUrl,
                'status'     => $statusCode,
                'snippet'    => substr($html, 0, 2000),
            ]);

            // ── DETEKSI SUKSES ──────────────────────────────────────────────
            //
            // PRIORITAS 1: Cek URL akhir setelah redirect
            // UniPin redirect ke /reload/result/... saat sukses
            $isSuccessByUrl = str_contains($finalUrl, '/reload/result/');

            // PRIORITAS 2: Cek class HTML spesifik dari halaman result UniPin
            // Berdasarkan struktur HTML: class="payment-success-badge" dan class="text-success"
            // yang hanya ada di halaman sukses
            $isSuccessByClass = str_contains($html, 'class="payment-success-badge"')
                             || str_contains($html, 'class="checkout-overview text-center"')
                             || str_contains($html, 'checkout-amount');

            // PRIORITAS 3: Teks spesifik dari halaman result (bukan keyword umum)
            $isSuccessByText = str_contains($html, 'Transaksi berhasil')
                            || str_contains($html, 'payment-success-check.svg')
                            || str_contains($html, 'alt="payment successful"');

            // ── DETEKSI GAGAL ───────────────────────────────────────────────
            //
            // Cek class/teks spesifik halaman gagal UniPin
            $isFailByClass = str_contains($html, 'class="payment-failed-badge"')
                          || str_contains($html, 'checkout-failed')
                          || str_contains($html, 'payment-failed');

            // Keyword gagal yang spesifik (hindari false positive dari JS/CSS)
            $isFailByText = str_contains($html, 'Consumed Voucher')
                         || str_contains($html, 'Voucher sudah digunakan')
                         || str_contains($html, 'already used')
                         || str_contains($html, 'sudah digunakan')
                         || str_contains($html, 'kadaluarsa')
                         || str_contains($html, 'Voucher tidak valid')
                         || str_contains($html, 'not found')
                         || str_contains($html, 'tidak ditemukan');

            $isSuccess = $isSuccessByUrl || $isSuccessByClass || $isSuccessByText;
            $isFailed  = $isFailByClass  || $isFailByText;

            // Ambil pesan dari halaman result
            $message    = $this->extractResultMessage($html);
            $amount     = $this->extractAmount($html);
            $noTrx      = $this->extractNoTransaksi($html);

            \Log::info('UnipinService deteksi result', [
                'kode'              => $kode,
                'is_success_url'    => $isSuccessByUrl,
                'is_success_class'  => $isSuccessByClass,
                'is_success_text'   => $isSuccessByText,
                'is_fail_class'     => $isFailByClass,
                'is_fail_text'      => $isFailByText,
                'final_url'         => $finalUrl,
                'message'           => $message,
                'amount'            => $amount,
            ]);

            sleep(3);

            if ($isSuccess && !$isFailed) {
                return [
                    'success'    => true,
                    'message'    => $message ?: 'Redeem berhasil',
                    'amount'     => $amount,
                    'no_transaksi' => $noTrx,
                    'kode'       => $kode,
                    'type'       => $type,
                ];
            }

            if ($isFailed) {
                return [
                    'success' => false,
                    'message' => $message ?: 'Redeem gagal',
                    'kode'    => $kode,
                    'type'    => $type,
                ];
            }

            // Tidak ada sinyal yang jelas → log HTML untuk debug
            \Log::warning('UnipinService: response tidak dikenali', [
                'kode'       => $kode,
                'final_url'  => $finalUrl,
                'snippet'    => substr($html, 0, 3000),
            ]);

            return [
                'success'    => false,
                'message'    => 'Response tidak dikenali — cek log untuk detail',
                'final_url'  => $finalUrl,
                'debug_html' => substr($html, 0, 3000),
                'kode'       => $kode,
                'type'       => $type,
            ];

        } catch (\Exception $e) {
            sleep(3);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ── Result extractors ───────────────────────────────────────────────────

    /**
     * Ambil pesan sukses/gagal dari halaman result UniPin.
     * Contoh: <span class="text-success">Transaksi berhasil, ...</span>
     */
    private function extractResultMessage(string $html): string
    {
        // Coba ambil dari span.text-success (halaman sukses)
        if (preg_match('/<span[^>]*class="[^"]*text-success[^"]*"[^>]*>(.*?)<\/span>/is', $html, $m)) {
            $text = trim(strip_tags($m[1]));
            if ($text) return $text;
        }

        // Coba ambil dari div.checkout-payment-name (nama transaksi)
        if (preg_match('/<div[^>]*class="[^"]*checkout-payment-name[^"]*"[^>]*>(.*?)<\/div>/is', $html, $m)) {
            $text = trim(strip_tags($m[1]));
            if ($text) return $text;
        }

        // Fallback: cari alert biasa
        $alertPatterns = [
            '/<div[^>]*class="[^"]*alert[^"]*"[^>]*>(.*?)<\/div>/is',
            '/<div[^>]*class="[^"]*toast[^"]*"[^>]*>(.*?)<\/div>/is',
            '/<p[^>]*class="[^"]*alert[^"]*"[^>]*>(.*?)<\/p>/is',
        ];

        foreach ($alertPatterns as $pattern) {
            if (preg_match($pattern, $html, $m)) {
                $text = trim(strip_tags($m[1]));
                if ($text) return $text;
            }
        }

        return '';
    }

    /**
     * Ambil nominal dari halaman sukses UniPin.
     * Contoh: <div class="checkout-amount">IDR 5.000</div>
     */
    private function extractAmount(string $html): string
    {
        if (preg_match('/<div[^>]*class="[^"]*checkout-amount[^"]*"[^>]*>(.*?)<\/div>/is', $html, $m)) {
            return trim(strip_tags($m[1]));
        }

        return '';
    }

    /**
     * Ambil nomor transaksi dari halaman sukses UniPin.
     */
    private function extractNoTransaksi(string $html): string
    {
        // Cari pola UUID di dekat teks "No. Transaksi"
        if (preg_match('/No\.\s*Transaksi.*?([a-f0-9\-]{30,})/is', $html, $m)) {
            return trim($m[1]);
        }

        // Fallback: cari UUID generik
        if (preg_match('/([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})/i', $html, $m)) {
            return $m[1];
        }

        return '';
    }

    // ── Parsing ─────────────────────────────────────────────────────────────

    public function parseKodePin(string $input): array
    {
        $input = trim($input);

        $formatKode = fn($k) => strtoupper(preg_replace('/[^A-Z0-9]/', '', trim($k)));

        // Format 1: KODE#PIN
        if (str_contains($input, '#')) {
            [$kode, $pin] = explode('#', $input, 2);
            $kode = $formatKode($kode);
            $pin  = preg_replace('/[\s\-]/', '', $pin);
            return ['kode' => $kode, 'kode_raw' => $kode, 'pin' => $pin, 'type' => $this->detectType($kode)];
        }

        // Format 2: KODE|PIN
        if (str_contains($input, '|')) {
            [$kode, $pin] = explode('|', $input, 2);
            $kode = $formatKode($kode);
            $pin  = preg_replace('/[\s\-]/', '', $pin);
            return ['kode' => $kode, 'kode_raw' => $kode, 'pin' => $pin, 'type' => $this->detectType($kode)];
        }

        $clean = preg_replace('/[\s\-]/', '', $input);

        // Format 3: string dengan 16 digit angka di akhir
        if (strlen($clean) > 16 && ctype_digit(substr($clean, -16))) {
            $pin  = substr($clean, -16);
            $kode = $formatKode(substr($clean, 0, strlen($clean) - 16));
            return ['kode' => $kode, 'kode_raw' => $kode, 'pin' => $pin, 'type' => $this->detectType($kode)];
        }

        // Format 4: ambil semua digit, 16 terakhir = PIN
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

    public function debugLogin(string $email, string $password): array
    {
        $this->destroySession();
        $this->createSession();

        $result = $this->proxyGet('/login');
        $html   = $result['response'] ?? '';

        preg_match('/<input[^>]*name="_token"[^>]*value="([^"]+)"/', $html, $tokenMatch);
        $token = $tokenMatch[1] ?? null;

        preg_match_all('/<input[^>]*name="([^"]+)"[^>]*>/', $html, $inputMatches);
        $dynamicFields = array_values(array_filter($inputMatches[1], fn($f) =>
            !in_array($f, ['_token', 'popup'])
        ));

        $response = $this->proxyClient->post('/request/post-no-redirect', [
            'json' => [
                'session_id' => $this->sessionId,
                'url'        => 'https://www.unipin.com/login',
                'post_data'  => [
                    '_token'          => $token,
                    'popup'           => '1',
                    $dynamicFields[0] => $email,
                    $dynamicFields[1] => $password,
                ],
            ],
        ]);

        $data = json_decode((string) $response->getBody(), true);

        return [
            'status_code'      => $data['status_code'],
            'location_header'  => $data['headers']['Location'] ?? $data['headers']['location'] ?? 'tidak ada',
            'cookies'          => $data['cookies'],
            'response_snippet' => substr($data['response'], 0, 500),
        ];
    }

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

    /**
     * Debug khusus untuk melihat raw response setelah redeem.
     * Panggil ini dari controller/tinker untuk inspeksi manual.
     */
    public function debugRedeem(string $input): array
    {
        if (!$this->refreshSessionIfNeeded()) {
            return ['error' => 'Gagal refresh session'];
        }

        $parsed = $this->parseKodePin($input);
        if (isset($parsed['error'])) {
            return ['error' => $parsed['error']];
        }

        $voucherId = match($parsed['type']) {
            'idmb'  => 49,
            'upgc'  => 50,
            default => null,
        };

        if (!$voucherId) {
            return ['error' => 'Tipe tidak dikenali: ' . $parsed['type']];
        }

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
            '_token' => $token,
            'serial' => $parsed['kode'],
            'pin_1'  => substr($pin, 0, 4),
            'pin_2'  => substr($pin, 4, 4),
            'pin_3'  => substr($pin, 8, 4),
            'pin_4'  => substr($pin, 12, 4),
        ]);

        return [
            'parsed'         => $parsed,
            'token'          => $token,
            'final_url'      => $redeemResult['final_url']   ?? '(tidak ada)',
            'status_code'    => $redeemResult['status_code'] ?? '(tidak ada)',
            'has_success_badge' => str_contains($redeemResult['response'] ?? '', 'payment-success-badge'),
            'has_success_text'  => str_contains($redeemResult['response'] ?? '', 'Transaksi berhasil'),
            'has_checkout_amt'  => str_contains($redeemResult['response'] ?? '', 'checkout-amount'),
            'amount'         => $this->extractAmount($redeemResult['response'] ?? ''),
            'message'        => $this->extractResultMessage($redeemResult['response'] ?? ''),
            'html_snippet'   => substr($redeemResult['response'] ?? '', 0, 3000),
        ];
    }
}