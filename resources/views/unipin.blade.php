<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>UniPin Manager</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --radius: 14px;
            --radius-sm: 10px;
            --accent: #4f7cff;
            --accent-hover: #3b68f5;
            --success: #10b981;
            --danger: #ef4444;
            --pending: #8b5cf6;
        }

        body.light {
            --bg: #eef2f7;
            --bg-card: #ffffff;
            --bg-card2: #f8fafc;
            --bg-input: #f3f4f6;
            --border: #e5e7eb;
            --border-hover: #d1d5db;
            --text: #111827;
            --text-muted: #6b7280;
            --text-dim: #9ca3af;
            --accent-glow: rgba(79,124,255,0.12);
            --success-bg: rgba(16,185,129,0.1);
            --danger-bg: rgba(239,68,68,0.1);
            --pending-bg: rgba(139,92,246,0.1);
        }

        body.dark {
            --bg: #0f172a;
            --bg-card: #111827;
            --bg-card2: #1e293b;
            --bg-input: #172033;
            --border: rgba(255,255,255,0.06);
            --border-hover: rgba(255,255,255,0.12);
            --text: #f3f4f6;
            --text-muted: #94a3b8;
            --text-dim: #64748b;
            --accent-glow: rgba(79,124,255,0.18);
            --success-bg: rgba(16,185,129,0.1);
            --danger-bg: rgba(239,68,68,0.1);
            --pending-bg: rgba(139,92,246,0.1);
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            font-size: 14px;
            line-height: 1.6;
        }

        .main-wrap { min-height: 100vh; display: flex; flex-direction: column; }

        .topbar { padding: 28px 28px 10px; display: flex; align-items: center; gap: 16px; }
        .brand-wrap { display: flex; align-items: center; gap: 14px; }
        .brand-icon {
            width: 42px; height: 42px; border-radius: 12px;
            background: var(--accent); color: white;
            display: flex; align-items: center; justify-content: center; font-size: 18px;
        }
        .brand-title { font-size: 28px; font-weight: 700; font-family: 'Space Grotesk', sans-serif; color: var(--text); line-height: 1.1; }
        .brand-sub { font-size: 13px; color: var(--text-muted); }
        .topbar-right { margin-left: auto; display: flex; align-items: center; gap: 12px; }

        .theme-toggle {
            width: 40px; height: 40px; border-radius: 12px;
            border: 1px solid var(--border); background: var(--bg-card);
            color: var(--text); cursor: pointer; transition: 0.2s; font-size: 15px;
        }
        .theme-toggle:hover { background: var(--bg-card2); }

        .status-pill {
            display: flex; align-items: center; gap: 7px;
            padding: 5px 12px; border-radius: 20px;
            font-size: 12px; font-weight: 500;
            border: 1px solid var(--border); background: var(--bg-input); color: var(--text-muted);
        }
        .status-pill.online { border-color: rgba(34,211,164,0.3); background: var(--success-bg); color: var(--success); }
        .status-dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
        .status-dot.pulse { animation: pulse 2s infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }

        .page-content {
            flex: 1; padding: 26px; max-width: 1600px; width: 100%; margin: 0 auto;
            display: grid; grid-template-columns: 420px 1fr; gap: 20px; align-items: start;
        }

        .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }

        .card-header {
            padding: 18px 20px 14px; border-bottom: 1px solid var(--border);
            display: flex; align-items: center; gap: 10px;
        }
        .card-icon { width: 30px; height: 30px; border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center; font-size: 14px; }
        .card-icon.blue  { background: var(--accent-glow); color: var(--accent); }
        .card-icon.green { background: var(--success-bg); color: var(--success); }
        .card-title { font-family: 'Space Grotesk', sans-serif; font-size: 13.5px; font-weight: 600; color: var(--text); flex: 1; }

        .card-body { padding: 20px; }
        .form-group { margin-bottom: 16px; }
        .form-label { display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px; font-size: 12px; font-weight: 500; color: var(--text-muted); letter-spacing: 0.3px; }

        .form-control {
            width: 100%; background: var(--bg-input); border: 1px solid var(--border);
            border-radius: var(--radius-sm); padding: 10px 12px;
            font-size: 13.5px; color: var(--text); font-family: 'DM Sans', sans-serif;
            outline: none; transition: all 0.15s;
        }
        .form-control::placeholder { color: var(--text-dim); }
        .form-control:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-glow); }

        textarea.form-control {
            resize: vertical; min-height: 190px;
            font-family: 'Space Grotesk', monospace; font-size: 12.5px; line-height: 1.7;
        }

        .input-group { position: relative; }
        .input-group .form-control { padding-right: 40px; }
        .input-toggle {
            position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
            background: none; border: none; color: var(--text-dim); cursor: pointer; font-size: 15px; padding: 2px;
        }
        .input-toggle:hover { color: var(--text-muted); }

        .count-badge {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 22px; height: 22px; border-radius: 11px;
            background: var(--accent-glow); border: 1px solid rgba(79,124,255,0.25);
            color: var(--accent); font-size: 11px; font-weight: 600;
            font-family: 'Space Grotesk', sans-serif; padding: 0 6px;
        }

        .storage-note {
            display: flex; align-items: center; gap: 8px; padding: 8px 12px; margin-bottom: 16px;
            background: var(--success-bg); border: 1px solid rgba(16,185,129,0.2);
            border-radius: var(--radius-sm); font-size: 11.5px; color: var(--success);
        }

        .btn-submit {
            width: 100%; padding: 11px 20px; background: var(--accent); border: none;
            border-radius: var(--radius-sm); color: #fff; font-size: 14px; font-weight: 500;
            font-family: 'DM Sans', sans-serif; cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.15s;
        }
        .btn-submit:hover { background: var(--accent-hover); transform: translateY(-1px); }
        .btn-submit:active { transform: translateY(0); }
        .btn-submit:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
        .btn-submit .spinner {
            width: 14px; height: 14px;
            border: 2px solid rgba(255,255,255,0.3); border-top-color: #fff;
            border-radius: 50%; animation: spin 0.6s linear infinite; display: none;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        .stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1px; background: var(--border); border-bottom: 1px solid var(--border); }
        .stat-cell { background: var(--bg-card2); padding: 14px 16px; text-align: center; }
        .stat-label { font-size: 11px; color: var(--text-muted); font-weight: 500; letter-spacing: 0.3px; margin-bottom: 4px; }
        .stat-value { font-family: 'Space Grotesk', sans-serif; font-size: 22px; font-weight: 700; }
        .stat-value.pending { color: var(--pending); }
        .stat-value.proses  { color: var(--accent); }
        .stat-value.sukses  { color: var(--success); }
        .stat-value.gagal   { color: var(--danger); }

        .monitor-controls { display: flex; align-items: center; gap: 10px; }
        .sync-info { font-size: 11px; color: var(--text-dim); white-space: nowrap; }

        .btn-sync {
            display: flex; align-items: center; gap: 5px; padding: 5px 10px;
            border: 1px solid var(--border); background: transparent;
            border-radius: var(--radius-sm); color: var(--text-muted); font-size: 11px;
            cursor: pointer; font-family: 'DM Sans', sans-serif; transition: all 0.15s;
        }
        .btn-sync:hover { background: var(--bg-card2); }

        .toggle-label { display: flex; align-items: center; gap: 8px; cursor: pointer; }
        .toggle-text { font-size: 12px; color: var(--text-muted); }
        .toggle-switch { position: relative; width: 36px; height: 20px; }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-track {
            position: absolute; inset: 0; background: var(--bg-input);
            border: 1px solid var(--border); border-radius: 10px; cursor: pointer; transition: 0.2s;
        }
        .toggle-track::after {
            content: ''; position: absolute; width: 14px; height: 14px; left: 2px; top: 2px;
            background: var(--text-dim); border-radius: 50%; transition: 0.2s;
        }
        .toggle-switch input:checked + .toggle-track { background: var(--accent); border-color: var(--accent); }
        .toggle-switch input:checked + .toggle-track::after { transform: translateX(16px); background: #fff; }

        .btn-danger-sm {
            display: flex; align-items: center; gap: 6px; padding: 6px 12px;
            border: 1px solid rgba(255,90,90,0.25); background: var(--danger-bg);
            border-radius: var(--radius-sm); color: var(--danger); font-size: 12px; font-weight: 500;
            cursor: pointer; transition: all 0.15s; font-family: 'DM Sans', sans-serif;
        }
        .btn-danger-sm:hover { background: rgba(255,90,90,0.18); border-color: rgba(255,90,90,0.4); }

        .monitor-table-wrap { overflow-x: auto; }
        .monitor-table { width: 100%; border-collapse: collapse; }
        .monitor-table thead th {
            padding: 10px 16px; font-size: 11px; font-weight: 600;
            color: var(--text-muted); letter-spacing: 0.5px; text-transform: uppercase;
            text-align: left; background: var(--bg-card2); border-bottom: 1px solid var(--border); white-space: nowrap;
        }
        .monitor-table tbody tr { border-bottom: 1px solid var(--border); transition: background 0.1s; }
        .monitor-table tbody tr:hover { background: rgba(255,255,255,0.02); }
        .monitor-table tbody td { padding: 11px 16px; font-size: 13px; vertical-align: middle; }

        .td-id { font-family: 'Space Grotesk', sans-serif; font-size: 12px; color: var(--text-muted); }
        .td-voucher { font-family: 'Space Grotesk', monospace; font-size: 12px; color: var(--text); max-width: 240px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 500; white-space: nowrap; }
        .badge-pending { background: var(--pending-bg); color: var(--pending); }
        .badge-proses  { background: var(--accent-glow); color: var(--accent); }
        .badge-sukses  { background: var(--success-bg); color: var(--success); }
        .badge-gagal   { background: var(--danger-bg); color: var(--danger); }
        .badge i { font-size: 9px; }

        .td-log { font-size: 12px; color: var(--text-muted); max-width: 260px; }

        .empty-state { padding: 60px 20px; text-align: center; }
        .empty-icon { font-size: 36px; color: var(--text-dim); margin-bottom: 12px; }
        .empty-title { font-size: 14px; font-weight: 500; color: var(--text-muted); margin-bottom: 4px; }
        .empty-sub   { font-size: 12px; color: var(--text-dim); }

        .log-terminal {
            background: #0a0c11; border-top: 1px solid var(--border);
            padding: 14px 16px; font-family: 'Space Grotesk', monospace; font-size: 11.5px;
            min-height: 90px; max-height: 160px; overflow-y: auto; color: #3ddc84;
        }
        .log-line { margin: 1px 0; }
        .log-time { color: var(--text-dim); margin-right: 6px; }
        .log-info    { color: var(--accent); }
        .log-success { color: var(--success); }
        .log-error   { color: var(--danger); }

        .toast-container { position: fixed; bottom: 24px; right: 24px; z-index: 999; display: flex; flex-direction: column; gap: 8px; }
        .toast {
            display: flex; align-items: flex-start; gap: 10px; padding: 12px 16px;
            border-radius: var(--radius); background: var(--bg-card2); border: 1px solid var(--border);
            min-width: 260px; max-width: 340px; animation: slideIn 0.2s ease;
            box-shadow: 0 8px 24px rgba(0,0,0,0.4);
        }
        @keyframes slideIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .toast.success { border-color: rgba(34,211,164,0.3); }
        .toast.error   { border-color: rgba(255,90,90,0.3); }
        .toast-icon { font-size: 15px; margin-top: 1px; }
        .toast.success .toast-icon { color: var(--success); }
        .toast.error   .toast-icon { color: var(--danger); }
        .toast-msg { font-size: 12.5px; color: var(--text); line-height: 1.5; }

        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--border-hover); border-radius: 3px; }
    </style>
</head>
<body class="light">

<div class="main-wrap">

    <header class="topbar">
        <div class="brand-wrap">
            <div class="brand-icon"><i class="fas fa-layer-group"></i></div>
            <div>
                <div class="brand-title">Sesss Manager</div>
                <div class="brand-sub">Background Processing System</div>
            </div>
        </div>
        <div class="topbar-right">
            <button class="theme-toggle" onclick="toggleTheme()">
                <i class="fas fa-moon" id="themeIcon"></i>
            </button>
            <div id="loginStatus" class="status-pill">
                <span class="status-dot"></span>
                Menunggu Login...
            </div>
        </div>
    </header>

    <div class="page-content">

        {{-- Left --}}
        <div class="card">
            <div class="card-header">
                <div class="card-icon blue"><i class="fas fa-upload"></i></div>
                <div class="card-title">Input Voucher</div>
            </div>
            <div class="card-body">

                <div class="storage-note">
                    <i class="fas fa-database"></i>
                    Riwayat tersimpan — aman lintas perangkat &amp; browser
                </div>

                <div class="form-group">
                    <label class="form-label">Email Akun</label>
                    <input type="email" class="form-control" id="emailInput"
                           placeholder="user@email.com" autocomplete="off">
                </div>

                <div class="form-group">
                    <label class="form-label">Password</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="passwordInput"
                               placeholder="Password akun UniPin...">
                        <button class="input-toggle" type="button" id="togglePass">
                            <i class="fas fa-eye" id="eyeIcon"></i>
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">
                        List Kode Voucher
                        <span class="count-badge" id="voucherCount">0</span>
                    </label>
                    <textarea class="form-control" id="kodeInput"
                              placeholder="SERIAL|PIN&#10;IDMB-7-S-00483259#5977-7672-9656-1991&#10;UPGC-XXXX#XXXX-XXXX-XXXX-XXXX"></textarea>
                    <div style="font-size: 11px; color: var(--text-dim); margin-top: 6px;">
                        <i class="fas fa-info-circle"></i>
                        Format:
                        <code style="color: var(--accent); background: var(--accent-glow); padding: 1px 5px; border-radius: 4px;">KODE#PIN</code>
                        atau
                        <code style="color: var(--accent); background: var(--accent-glow); padding: 1px 5px; border-radius: 4px;">KODE|PIN</code>
                        — satu baris satu voucher
                    </div>
                </div>

                <button class="btn-submit" id="submitBtn" onclick="kirimKeServer()">
                    <span class="spinner" id="spinner"></span>
                    <i class="fas fa-cloud-upload-alt" id="btnIcon"></i>
                    <span id="btnText">Kirim ke Server</span>
                </button>
            </div>
        </div>

        {{-- Right --}}
        <div class="card" style="display: flex; flex-direction: column;">
            <div class="card-header">
                <div class="card-icon green"><i class="fas fa-desktop"></i></div>
                <div class="card-title">Live Monitor</div>
                <div class="monitor-controls">
                    <span class="sync-info" id="syncInfo">—</span>
                    <button class="btn-sync" onclick="loadJobs(true)">
                        <i class="fas fa-sync-alt" id="syncIcon"></i> Sync
                    </button>
                    <button class="btn-danger-sm" onclick="bersihkanHistory()">
                        <i class="fas fa-trash"></i> Bersihkan
                    </button>
                </div>
            </div>

            <div class="stats-row">
                <div class="stat-cell"><div class="stat-label">Pending</div><div class="stat-value pending" id="statPending">0</div></div>
                <div class="stat-cell"><div class="stat-label">Proses</div><div class="stat-value proses" id="statProses">0</div></div>
                <div class="stat-cell"><div class="stat-label">Sukses</div><div class="stat-value sukses" id="statSukses">0</div></div>
                <div class="stat-cell"><div class="stat-label">Gagal</div><div class="stat-value gagal" id="statGagal">0</div></div>
            </div>

            <div class="monitor-table-wrap" style="flex: 1;">
                <table class="monitor-table">
                    <thead>
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th>Voucher</th>
                            <th style="width: 110px;">Status</th>
                            <th>Log Server</th>
                        </tr>
                    </thead>
                    <tbody id="monitorBody">
                        <tr><td colspan="4">
                            <div class="empty-state">
                                <div class="empty-icon"><i class="fas fa-clock"></i></div>
                                <div class="empty-title">Memuat dari database...</div>
                                <div class="empty-sub">Harap tunggu sebentar</div>
                            </div>
                        </td></tr>
                    </tbody>
                </table>
            </div>

            <div class="log-terminal" id="logTerminal">
                <div class="log-line">
                    <span class="log-time">00:00:00</span>
                    <span class="log-info">● System ready. Memuat riwayat dari Database...</span>
                </div>
            </div>
        </div>

    </div>
</div>

<div class="toast-container" id="toastContainer"></div>

<script>
const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

let jobs       = [];
let uiSubmitting = false;
let processingQueue = false;

// ── Fetch helpers ───────────────────────────────────────────────────────────

async function api(method, url, body = null) {
    const opts = {
        method,
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
    };
    if (body) opts.body = JSON.stringify(body);
    const r = await fetch(url, opts);
    const text = await r.text();

    try {
        return JSON.parse(text);
    } catch {
        throw new Error('Response bukan JSON');
    }
}

// ── Load jobs dari SQLite ───────────────────────────────────────────────────

async function loadJobs(manual = false) {
    const email = document.getElementById('emailInput').value.trim();
    if (!email) {
        if (manual) toast('Masukkan email dulu untuk sync data', 'error');
        return;
    }

    if (manual) document.getElementById('syncIcon').classList.add('fa-spin');

    try {
        const data = await api('GET', `/unipin/jobs?email=${encodeURIComponent(email)}`);
        jobs = data.jobs || [];

        // Reset status "proses" → "pending" kalau tab ditutup di tengah jalan
        const prosesIds = jobs.filter(j => j.status === 'proses').map(j => j.id);
        for (const id of prosesIds) {
            await api('PATCH', `/unipin/jobs/${id}`, { status: 'pending', log: 'Dilanjutkan setelah reconnect' });
        }
        if (prosesIds.length) {
            jobs = jobs.map(j => j.status === 'proses' ? { ...j, status: 'pending', log: 'Dilanjutkan setelah reconnect' } : j);
        }

        if (data.savedAt) {
            const waktu = new Date(data.savedAt).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            log(`Riwayat dimuat: ${jobs.length} voucher (update terakhir ${waktu})`, 'info');
        } else {
            log('Belum ada riwayat. Siap menerima voucher baru.', 'info');
        }

        const jam = new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
        document.getElementById('syncInfo').textContent = 'Sync ' + jam;
        if (manual) toast('Sync berhasil!');

    } catch (e) {
        log('Gagal memuat dari database: ' + e.message, 'error');
    }

    if (manual) document.getElementById('syncIcon').classList.remove('fa-spin');
    renderTable();
}

// ── UI helpers ──────────────────────────────────────────────────────────────

function toast(msg, type = 'success') {
    const el = document.createElement('div');
    el.className = `toast ${type}`;
    el.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'times-circle'} toast-icon"></i><div class="toast-msg">${msg}</div>`;
    document.getElementById('toastContainer').appendChild(el);
    setTimeout(() => el.remove(), 3800);
}

function log(msg, type = '') {
    const t   = document.getElementById('logTerminal');
    const now = new Date().toTimeString().slice(0, 8);
    const d   = document.createElement('div');
    d.className = 'log-line';
    d.innerHTML = `<span class="log-time">${now}</span><span class="${type ? 'log-' + type : ''}">${msg}</span>`;
    t.appendChild(d);
    t.scrollTop = t.scrollHeight;
}

document.getElementById('togglePass').addEventListener('click', () => {
    const inp = document.getElementById('passwordInput');
    const ico = document.getElementById('eyeIcon');
    inp.type = inp.type === 'password' ? 'text' : 'password';
    ico.className = inp.type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
});

document.getElementById('kodeInput').addEventListener('input', () => {
    const n = document.getElementById('kodeInput').value.split('\n').filter(l => l.trim().length > 3).length;
    document.getElementById('voucherCount').textContent = n;
});

// ── Email input: auto-load saat email diisi ─────────────────────────────────
document.getElementById('emailInput').addEventListener('blur', () => {
    if (document.getElementById('emailInput').value.trim()) loadJobs();
});

// ── Stats & Table ───────────────────────────────────────────────────────────

function updateStats() {
    document.getElementById('statPending').textContent = jobs.filter(j => j.status === 'pending').length;
    document.getElementById('statProses').textContent  = jobs.filter(j => j.status === 'proses').length;
    document.getElementById('statSukses').textContent  = jobs.filter(j => j.status === 'sukses').length;
    document.getElementById('statGagal').textContent   = jobs.filter(j => j.status === 'gagal').length;
}

function renderTable() {
    const tbody = document.getElementById('monitorBody');
    if (!jobs.length) {
        tbody.innerHTML = `<tr><td colspan="4"><div class="empty-state"><div class="empty-icon"><i class="fas fa-lock"></i></div><div class="empty-title">Belum ada data</div><div class="empty-sub">Masukkan email & password lalu kirim voucher</div></div></td></tr>`;
        updateStats(); return;
    }
    const badges = {
        pending: `<span class="badge badge-pending"><i class="fas fa-clock"></i> Pending</span>`,
        proses:  `<span class="badge badge-proses"><i class="fas fa-spinner fa-spin"></i> Proses</span>`,
        sukses:  `<span class="badge badge-sukses"><i class="fas fa-check"></i> Sukses</span>`,
        gagal:   `<span class="badge badge-gagal"><i class="fas fa-times"></i> Gagal</span>`,
    };
    tbody.innerHTML = jobs.map(j => `
        <tr>
            <td class="td-id">#${j.id}</td>
            <td class="td-voucher" title="${j.kode}">${j.kode}</td>
            <td>${badges[j.status] || j.status}</td>
            <td class="td-log">${j.log || '—'}</td>
        </tr>`).join('');
    updateStats();
}

async function processPendingJobs(email, password) {
    if (processingQueue) return;

    const pendingJobs = jobs.filter(j => j.status === 'pending');

    if (!pendingJobs.length) {
        log('Tidak ada queue pending', 'info');
        return;
    }

    processingQueue = true;

    log(`Melanjutkan ${pendingJobs.length} queue pending...`, 'info');

    for (const job of pendingJobs) {

        await api('PATCH', `/unipin/jobs/${job.id}`, {
            status: 'proses',
            log: 'Sedang diredeem...'
        });

        job.status = 'proses';
        job.log = 'Sedang diredeem...';

        renderTable();

        try {

            const result = await api('POST', '/unipin/redeem', {
                kode: job.kode,
                email,
                password
            });

            if (result.success) {

                await api('PATCH', `/unipin/jobs/${job.id}`, {
                    status: 'sukses',
                    log: result.message || 'Redeem berhasil',
                    amount: result.amount || '',
                    no_transaksi: result.no_transaksi || '',
                });

                job.status = 'sukses';
                job.log = result.message || 'Redeem berhasil';

                log(`[#${job.id}] SUKSES`, 'success');

            } else {

                await api('PATCH', `/unipin/jobs/${job.id}`, {
                    status: 'gagal',
                    log: result.message || 'Gagal'
                });

                job.status = 'gagal';
                job.log = result.message || 'Gagal';

                log(`[#${job.id}] GAGAL`, 'error');
            }

        } catch (e) {

            await api('PATCH', `/unipin/jobs/${job.id}`, {
                status: 'gagal',
                log: 'Error: ' + e.message
            });

            job.status = 'gagal';
            job.log = 'Error: ' + e.message;

            log(`[#${job.id}] ERROR: ${e.message}`, 'error');
        }

        renderTable();

        await delay(500);
    }

    processingQueue = false;

    log('Resume queue selesai', 'success');
}

// ── Kirim ke server ─────────────────────────────────────────────────────────

async function kirimKeServer() {
    if (uiSubmitting) return;

    const email    = document.getElementById('emailInput').value.trim();
    const password = document.getElementById('passwordInput').value.trim();
    const rawKode  = document.getElementById('kodeInput').value.trim();

    if (!email || !password) { toast('Email & password wajib diisi!', 'error'); return; }

    const lines = rawKode.split('\n').map(l => l.trim()).filter(l => l.length > 3);
    if (!lines.length) { toast('Minimal 1 kode voucher!', 'error'); return; }

    uiSubmitting = true;
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    document.getElementById('spinner').style.display = 'block';
    document.getElementById('btnIcon').style.display  = 'none';
    document.getElementById('btnText').textContent     = 'Mengirim...';

    const pill = document.getElementById('loginStatus');
    pill.className = 'status-pill';
    pill.innerHTML = `<span class="status-dot pulse"></span> Proses Login...`;

    log(`Memulai sesi login untuk ${email}...`, 'info');

    try {
        // ── 1. Login ──
        const loginData = await api('POST', '/unipin/login', { email, password });
        if (!loginData.success) {
            toast('Login gagal: ' + (loginData.message || 'Periksa email/password'), 'error');
            log(`Login GAGAL: ${loginData.message}`, 'error');
            resetBtn();
            pill.innerHTML = `<span class="status-dot"></span> Login Gagal`;
            return;
        }

        pill.className = 'status-pill online';
        pill.innerHTML = `<span class="status-dot"></span> Online`;
        log('Login berhasil!', 'success');
        toast('Login berhasil!');

        await processPendingJobs(email, password);

        // ── 2. Simpan kode baru ke SQLite ──
        const addResult = await api('POST', '/unipin/jobs/add', { email, kodes: lines });
        log(`${addResult.tambah} voucher ditambahkan ke antrian`, 'info');

        // Tambah job baru ke array lokal langsung dari response — tidak perlu fetch ulang
        const newJobs = addResult.newJobs || [];
        jobs.push(...newJobs);
        renderTable();

        log(`${newJobs.length} voucher masuk queue`, 'info');

        // LANGSUNG proses queue
        await processPendingJobs(email, password);


        
        const sukses = jobs.filter(j => j.status === 'sukses').length;
        const gagal  = jobs.filter(j => j.status === 'gagal').length;
        log(`Selesai! Sukses: ${sukses} | Gagal: ${gagal}`, sukses > 0 ? 'success' : 'error');
        toast(`Selesai! ${sukses} sukses, ${gagal} gagal`, sukses > 0 ? 'success' : 'error');

    } catch (e) {
        toast('Error: ' + e.message, 'error');
        log('Error tidak terduga: ' + e.message, 'error');
    }

    resetBtn();
}

function resetBtn() {
    uiSubmitting = false;
    document.getElementById('submitBtn').disabled = false;
    document.getElementById('spinner').style.display = 'none';
    document.getElementById('btnIcon').style.display  = 'inline';
    document.getElementById('btnText').textContent     = 'Kirim ke Server';
}

function delay(ms) { return new Promise(r => setTimeout(r, ms)); }

// ── Bersihkan history ───────────────────────────────────────────────────────

async function bersihkanHistory() {
    if (!jobs.length) return;
    const email = document.getElementById('emailInput').value.trim();
    if (!email) { toast('Masukkan email dulu', 'error'); return; }
    try {
        const r = await api('DELETE', '/unipin/jobs', { email });
        log(`History dihapus (${r.deleted} item), antrian aktif dipertahankan`, 'info');
        toast('History berhasil dibersihkan');
        await loadJobs();
    } catch (e) {
        toast('Gagal bersihkan: ' + e.message, 'error');
    }
}

// ── Theme ───────────────────────────────────────────────────────────────────

function toggleTheme() {
    const body = document.body;
    const icon = document.getElementById('themeIcon');
    if (body.classList.contains('light')) {
        body.classList.replace('light', 'dark');
        icon.className = 'fas fa-sun';
        localStorage.setItem('theme', 'dark');
    } else {
        body.classList.replace('dark', 'light');
        icon.className = 'fas fa-moon';
        localStorage.setItem('theme', 'light');
    }
}

(function initTheme() {
    const saved = localStorage.getItem('theme') || 'light';
    document.body.classList.remove('light', 'dark');
    document.body.classList.add(saved);
    document.getElementById('themeIcon').className = saved === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
})();
</script>
</body>
</html>