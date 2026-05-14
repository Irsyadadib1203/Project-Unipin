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

    /**
     * Set kredensial tanpa login ulang.
     * Dipakai oleh controller agar refreshSessionIfNeeded() punya fallback.
     */
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
            // Destroy sesi lama HANYA jika ada, lalu buat baru
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

            // Coba dua kemungkinan form ID
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

            // POST login
            $this->proxyPost('/login', $formData);

            // Verifikasi login
            $checkResult = $this->proxyGet('/profile');
            $checkHtml   = $checkResult['response'] ?? '';

            $isLoggedIn = str_contains($checkHtml, 'Logout')
                       || str_contains($checkHtml, 'Keluar')
                       || str_contains($checkHtml, $email);

            if ($isLoggedIn) {
                $this->loginTime = time();

                // Simpan ke Laravel session agar bisa dipakai request berikutnya
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

    /**
     * Pastikan sessionId aktif sebelum melakukan request.
     *
     * Priority:
     * 1. Pakai sessionId di object ini (kalau masih dalam 10 menit)
     * 2. Pakai sessionId dari Laravel session (kalau masih dalam 10 menit)
     * 3. Login ulang pakai kredensial yang tersimpan
     */
    protected function refreshSessionIfNeeded(): bool
    {
        // 1. Object sudah punya session aktif
        if ($this->sessionId && $this->loginTime > 0) {
            if ((time() - $this->loginTime) <= 600) {
                return true;
            }
        }

        // 2. Coba pakai session Laravel yang disimpan saat login
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

        // 3. Tidak ada session valid → login ulang
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

            // Cek kalau redirect ke halaman login
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

            // Ambil CSRF token — coba meta tag dulu, lalu input hidden
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

            $html = $redeemResult['response'] ?? '';

            // ── Log response untuk debugging ──
            preg_match('/<title>(.*?)<\/title>/i', $html, $titleMatch);
            $title = $titleMatch[1] ?? '';

            // Cari pesan alert (coba berbagai pola class)
            $alertText = '';
            $alertPatterns = [
                '/<div[^>]*class="[^"]*alert[^"]*"[^>]*>(.*?)<\/div>/is',
                '/<div[^>]*class="[^"]*toast[^"]*"[^>]*>(.*?)<\/div>/is',
                '/<div[^>]*class="[^"]*message[^"]*"[^>]*>(.*?)<\/div>/is',
                '/<p[^>]*class="[^"]*alert[^"]*"[^>]*>(.*?)<\/p>/is',
            ];
            foreach ($alertPatterns as $pattern) {
                if (preg_match($pattern, $html, $alertMatch)) {
                    $alertText = trim(strip_tags($alertMatch[1]));
                    if ($alertText) break;
                }
            }

            \Log::debug('UnipinService redeemKode response', [
                'kode'    => $kode,
                'type'    => $type,
                'title'   => $title,
                'alert'   => $alertText,
                'snippet' => substr($html, 0, 2000),
            ]);

            // ── Deteksi hasil ──
            $htmlLower = strtolower($html);

            $successKeywords = [
                'berhasil', 'success', 'sukses', 'credited', 'topup',
                'telah ditambahkan', 'reload berhasil', 'voucher berhasil',
                'successfully', 'added', 'diisi',
            ];
            $failKeywords = [
                'gagal', 'failed', 'invalid', 'tidak valid', 'expired',
                'sudah digunakan', 'already used', 'not found',
                'tidak ditemukan', 'habis', 'kadaluarsa',
            ];

            // Khusus: kata "error" hanya dianggap gagal kalau bukan dalam konteks JS/CSS biasa
            $isSuccess = false;
            $isFailed  = false;

            foreach ($successKeywords as $kw) {
                if (str_contains($htmlLower, $kw)) {
                    $isSuccess = true;
                    break;
                }
            }

            foreach ($failKeywords as $kw) {
                if (str_contains($htmlLower, $kw)) {
                    $isFailed = true;
                    break;
                }
            }

            // Sukses menang kalau keduanya ada (misal alert sukses tapi ada kata "error" di JS)
            if ($isSuccess && !$isFailed) {
                sleep(3);
                return [
                    'success' => true,
                    'message' => $alertText ?: 'Redeem berhasil',
                    'kode'    => $kode,
                    'type'    => $type,
                ];
            }

            if ($isFailed) {
                sleep(3);
                return [
                    'success' => false,
                    'message' => $alertText ?: 'Redeem gagal',
                    'kode'    => $kode,
                    'type'    => $type,
                ];
            }

            // Tidak ada keyword yang cocok → kembalikan debug info
            sleep(3);
            return [
                'success'    => false,
                'message'    => 'Response tidak dikenali — cek log untuk detail',
                'title'      => $title,
                'alert'      => $alertText,
                'debug_html' => substr($html, 0, 3000),
            ];

        } catch (\Exception $e) {
            sleep(3);
            return ['success' => false, 'message' => $e->getMessage()];
        }
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
}