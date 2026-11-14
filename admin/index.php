<?php
/**
 * WiFi Hotspot Admin panel
 * Kurasa: ?p=orders (maombi) | vouchers | sms | settings | password
 */
require __DIR__ . '/../lib.php';
session_set_cookie_params(['httponly' => true, 'secure' => true, 'samesite' => 'Lax']);
session_name('vwadmin');
session_start();
$db = vw_db();

function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(16)); }
function csrf_ok(): bool { return hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? '')); }
function go(string $q, string $msg = ''): void { if ($msg) $_SESSION['flash'] = $msg; header('Location: ./?' . $q); exit; }

$hasAdmin = (int)$db->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0;
$p = $_GET['p'] ?? 'orders';
$err = '';

// ---------- Kuweka admin wa kwanza (inahitaji SETUP CODE) ----------
if (!$hasAdmin) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $code = vw_secrets()['SETUP_CODE'] ?? '';
        $u = trim((string)($_POST['u'] ?? '')); $pw = (string)($_POST['pw'] ?? '');
        if ($code === '' || !hash_equals($code, trim((string)($_POST['code'] ?? '')))) $err = 'Setup code si sahihi.';
        elseif (!preg_match('/^[a-zA-Z0-9_.]{3,30}$/', $u)) $err = 'Username: herufi/tarakimu 3-30.';
        elseif (strlen($pw) < 8) $err = 'Password angalau herufi 8.';
        else {
            $db->prepare('INSERT INTO admins (username, pass_hash, created_at) VALUES (?, ?, ?)')->execute([$u, password_hash($pw, PASSWORD_DEFAULT), vw_now()]);
            session_regenerate_id(true); $_SESSION['admin'] = $u; go('p=orders', 'Admin ameundwa. Karibu!');
        }
    }
    page_head('Weka Admin');
    echo '<div class="box narrow"><h2>Weka akaunti ya Admin (mara moja tu)</h2>' . ($err ? '<p class="err">' . vw_e($err) . '</p>' : '') .
        '<form method="post"><label>Setup code</label><input name="code" required autocomplete="off">
        <label>Username</label><input name="u" required><label>Password (angalau 8)</label><input name="pw" type="password" required>
        <button>Hifadhi</button></form></div>';
    page_foot(); exit;
}

// ---------- Login / logout ----------
if ($p === 'logout') { session_destroy(); go(''); }
if (empty($_SESSION['admin'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $_SESSION['tries'] = ($_SESSION['tries'] ?? 0) + 1;
        if ($_SESSION['tries'] > 8) { sleep(3); }
        $st = $db->prepare('SELECT * FROM admins WHERE username = ?'); $st->execute([trim((string)($_POST['u'] ?? ''))]);
        $a = $st->fetch();
        if ($a && password_verify((string)($_POST['pw'] ?? ''), $a['pass_hash'])) {
            session_regenerate_id(true); $_SESSION['admin'] = $a['username']; $_SESSION['tries'] = 0; go('p=orders');
        }
        $err = 'Username au password si sahihi.';
    }
    page_head('Ingia');
    echo '<div class="box narrow"><h2>' . vw_e(vw_setting('business_name')) . ' — Admin</h2>' . ($err ? '<p class="err">' . vw_e($err) . '</p>' : '') .
        '<form method="post"><label>Username</label><input name="u" required autofocus><label>Password</label><input name="pw" type="password" required><button>Ingia</button></form></div>';
    page_foot(); exit;
}
$me = $_SESSION['admin'];

// ---------- Vitendo (POST) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) go('p=' . $p, 'Jaribu tena (fomu imeisha muda).');
    $a = $_POST['a'] ?? '';
    if ($a === 'approve') {
        go('p=orders', vw_approve((int)$_POST['id'], $me) ? 'Imeidhinishwa. Router itaweka vocha ndani ya sekunde 20.' : 'Haikuidhinishwa (huenda imeshashughulikiwa).');
    }
    if ($a === 'reject') {
        $db->prepare("UPDATE orders SET status='rejected', note=?, approved_by=?, updated_at=? WHERE id=? AND status='pending'")
           ->execute([trim((string)($_POST['note'] ?? '')) ?: 'Malipo hayakuonekana.', $me, vw_now(), (int)$_POST['id']]);
        go('p=orders', 'Ombi limekataliwa.');
    }
    if ($a === 'vouchers') {
        $pkg = (string)($_POST['package'] ?? ''); $n = max(1, min(200, (int)($_POST['n'] ?? 0)));
        if (!isset(VW_PACKAGES[$pkg])) go('p=vouchers', 'Chagua kifurushi.');
        $batch = date('Ymd-His'); $now = vw_now();
        $ins = $db->prepare("INSERT INTO orders (kind, package, amount, status, username, password, batch, approved_by, created_at, updated_at) VALUES ('voucher', ?, ?, 'approved', ?, ?, ?, ?, ?, ?)");
        for ($i = 0; $i < $n; $i++) { $c = vw_code(); $ins->execute([$pkg, VW_PACKAGES[$pkg]['price'], $c, $c, $batch, $me, $now, $now]); }
        go('p=vouchers&batch=' . $batch, "Vocha $n zimetengenezwa. Router inaziweka (vocha 5 kila sekunde 20).");
    }
    if ($a === 'settings') {
        $st = $db->prepare('INSERT OR REPLACE INTO settings (k, v) VALUES (?, ?)');
        foreach (array_keys(VW_DEFAULT_SETTINGS) as $k) $st->execute([$k, trim((string)($_POST[$k] ?? ''))]);
        go('p=settings', 'Mipangilio imehifadhiwa.');
    }
    if ($a === 'password') {
        $st = $db->prepare('SELECT pass_hash FROM admins WHERE username = ?'); $st->execute([$me]);
        if (!password_verify((string)($_POST['old'] ?? ''), (string)$st->fetchColumn())) go('p=password', 'Password ya zamani si sahihi.');
        if (strlen((string)($_POST['new'] ?? '')) < 8) go('p=password', 'Password mpya angalau herufi 8.');
        $db->prepare('UPDATE admins SET pass_hash = ? WHERE username = ?')->execute([password_hash((string)$_POST['new'], PASSWORD_DEFAULT), $me]);
        go('p=password', 'Password imebadilishwa.');
    }
}

// ---------- Kurasa ----------
$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
$seen = $db->query('SELECT at FROM router_seen WHERE id = 1')->fetchColumn();
$routerOk = $seen && (time() - strtotime($seen)) < 120;
$pending = (int)$db->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn();
$printing = isset($_GET['print']);

page_head(vw_setting('business_name') . ' Admin', $printing);
if (!$printing) {
    echo '<nav><b>' . vw_e(vw_setting('business_name')) . '</b>';
    foreach (['orders' => "Maombi ($pending)", 'vouchers' => 'Vocha', 'sms' => 'SMS za malipo', 'settings' => 'Mipangilio', 'password' => 'Password'] as $k => $t)
        echo '<a href="?p=' . $k . '"' . ($p === $k ? ' class="on"' : '') . '>' . vw_e($t) . '</a>';
    echo '<span class="rt ' . ($routerOk ? 'ok' : 'bad') . '">Router: ' . ($routerOk ? 'imeunganishwa' : 'HAIJAONEKANA' . ($seen ? ' tangu ' . vw_e($seen) : '')) . '</span>';
    echo '<a href="?p=logout">Toka</a></nav>';
    if ($flash) echo '<p class="flash">' . vw_e($flash) . '</p>';
}
$csrf = '<input type="hidden" name="csrf" value="' . csrf() . '">';
$pkgOpts = ''; foreach (VW_PACKAGES as $k => $v) $pkgOpts .= '<option value="' . $k . '">' . vw_e($v['label']) . ' - ' . number_format($v['price']) . ' TSh</option>';

if ($p === 'orders') {
    echo '<div class="box"><h2>Maombi yanayosubiri (malipo ya mkono)</h2><p class="hint">Angalia SMS ya malipo kwenye simu ya ' . vw_e(vw_setting('pay_number')) .
        '. Kama namba ya muamala na kiasi vinalingana, bonyeza <b>Idhinisha</b>. Malipo yenye SMS iliyotumwa na app yanaidhinishwa yenyewe.</p>';
    $rows = $db->query("SELECT * FROM orders WHERE status='pending' ORDER BY id DESC")->fetchAll();
    if (!$rows) echo '<p>Hakuna ombi linalosubiri.</p>';
    else {
        echo '<table><tr><th>#</th><th>Saa</th><th>Simu</th><th>Kifurushi</th><th>Kiasi</th><th>Namba ya muamala</th><th></th></tr>';
        foreach ($rows as $o) echo '<tr><td>' . $o['id'] . '</td><td>' . vw_e($o['created_at']) . '</td><td>' . vw_e($o['phone']) . '</td><td>' . vw_e(VW_PACKAGES[$o['package']]['label'] ?? $o['package']) .
            '</td><td>' . number_format((int)$o['amount']) . '</td><td><b>' . vw_e($o['pay_ref']) . '</b></td><td class="act">' .
            '<form method="post">' . $csrf . '<input type="hidden" name="a" value="approve"><input type="hidden" name="id" value="' . $o['id'] . '"><button class="g">Idhinisha</button></form>' .
            '<form method="post" onsubmit="return confirm(\'Kataa ombi hili?\')">' . $csrf . '<input type="hidden" name="a" value="reject"><input type="hidden" name="id" value="' . $o['id'] . '"><input name="note" placeholder="Sababu (hiari)"><button class="r">Kataa</button></form></td></tr>';
        echo '</table>';
    }
    echo '</div><div class="box"><h2>Mauzo ya karibuni</h2><table><tr><th>#</th><th>Saa</th><th>Simu</th><th>Kifurushi</th><th>Kiasi</th><th>Muamala</th><th>Hali</th><th>Vocha</th><th>Na</th></tr>';
    foreach ($db->query("SELECT * FROM orders WHERE kind='order' AND status<>'pending' ORDER BY id DESC LIMIT 50") as $o)
        echo '<tr><td>' . $o['id'] . '</td><td>' . vw_e($o['updated_at']) . '</td><td>' . vw_e($o['phone']) . '</td><td>' . vw_e(VW_PACKAGES[$o['package']]['label'] ?? '') . '</td><td>' . number_format((int)$o['amount']) .
            '</td><td>' . vw_e($o['pay_ref']) . '</td><td>' . vw_e(['approved' => 'Inasubiri router', 'active' => 'Imetumika/ipo', 'rejected' => 'Imekataliwa'][$o['status']] ?? $o['status']) . '</td><td>' . vw_e($o['username']) . '</td><td>' . vw_e($o['approved_by']) . '</td></tr>';
    echo '</table>';
    $tot = $db->query("SELECT COALESCE(SUM(amount),0) FROM orders WHERE kind='order' AND status IN ('approved','active') AND date(updated_at)=date('now','localtime')")->fetchColumn();
    echo '<p><b>Mauzo ya leo (malipo ya simu): ' . number_format((int)$tot) . ' TSh</b></p></div>';
}

if ($p === 'vouchers') {
    $batch = preg_replace('/[^0-9-]/', '', (string)($_GET['batch'] ?? ''));
    if (!$printing) {
        echo '<div class="box"><h2>Tengeneza vocha (kuuza kwa mkono)</h2><form method="post" class="inline">' . $csrf . '<input type="hidden" name="a" value="vouchers">
            <select name="package">' . $pkgOpts . '</select><input name="n" type="number" min="1" max="200" value="20"><button>Tengeneza</button></form></div>';
        echo '<div class="box"><h2>Makundi ya vocha</h2><table><tr><th>Kundi</th><th>Kifurushi</th><th>Idadi</th><th>Zipo kwenye router</th><th></th></tr>';
        foreach ($db->query("SELECT batch, package, COUNT(*) n, SUM(status='active') a FROM orders WHERE kind='voucher' GROUP BY batch ORDER BY batch DESC LIMIT 30") as $b)
            echo '<tr><td>' . vw_e($b['batch']) . '</td><td>' . vw_e(VW_PACKAGES[$b['package']]['label'] ?? '') . '</td><td>' . $b['n'] . '</td><td>' . (int)$b['a'] . '</td><td><a href="?p=vouchers&batch=' . vw_e($b['batch']) . '&print=1" target="_blank">Chapisha</a></td></tr>';
        echo '</table></div>';
    }
    if ($batch && $printing) {
        $st = $db->prepare("SELECT * FROM orders WHERE kind='voucher' AND batch=? ORDER BY id"); $st->execute([$batch]);
        echo '<div class="cards">';
        foreach ($st as $v) echo '<div class="card"><div class="bn">' . vw_e(vw_setting('business_name')) . '</div><div class="pk">' . vw_e(VW_PACKAGES[$v['package']]['label'] ?? '') . ' - ' . number_format((int)$v['amount']) . ' TSh</div><div class="cd">' . vw_e($v['username']) . '</div><div class="ft">Unganisha WiFi, andika vocha hii. Msaada: ' . vw_e(vw_setting('support_phone')) . '</div></div>';
        echo '</div><script>window.print()</script>';
    }
}

if ($p === 'sms') {
    echo '<div class="box"><h2>SMS za malipo (automatic)</h2><p class="hint">SMS zinazotumwa na app ya "SMS Forwarder" kutoka simu ya malipo. Ikilingana na ombi la mteja (namba ya muamala + kiasi), vocha inatolewa yenyewe.</p><table><tr><th>Saa</th><th>Kutoka</th><th>SMS</th><th>Ombi</th></tr>';
    foreach ($db->query('SELECT * FROM sms ORDER BY id DESC LIMIT 100') as $s)
        echo '<tr><td>' . vw_e($s['created_at']) . '</td><td>' . vw_e($s['sender']) . '</td><td class="sms">' . vw_e($s['body']) . '</td><td>' . ($s['order_id'] ? '#' . (int)$s['order_id'] : '-') . '</td></tr>';
    echo '</table></div>';
}

if ($p === 'settings') {
    $labels = ['business_name' => 'Jina la biashara', 'pay_number' => 'Namba ya kupokea malipo', 'pay_name' => 'Jina linaloonekana mteja akilipa', 'support_phone' => 'Namba ya msaada'];
    echo '<div class="box narrow"><h2>Mipangilio</h2><form method="post">' . $csrf . '<input type="hidden" name="a" value="settings">';
    foreach ($labels as $k => $t) echo '<label>' . $t . '</label><input name="' . $k . '" value="' . vw_e(vw_setting($k)) . '" required>';
    echo '<button>Hifadhi</button></form><p class="hint">Vifurushi na bei vinabadilishwa kwenye router (Mikhmon) na kwenye lib.php (VW_PACKAGES) kwa pamoja.</p></div>';
}

if ($p === 'password') {
    echo '<div class="box narrow"><h2>Badilisha password</h2><form method="post">' . $csrf . '<input type="hidden" name="a" value="password">
        <label>Password ya sasa</label><input name="old" type="password" required><label>Password mpya (angalau 8)</label><input name="new" type="password" required><button>Badilisha</button></form></div>';
}
page_foot();

function page_head(string $t, bool $print = false): void
{
    echo '<!doctype html><html lang="sw"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . vw_e($t) . '</title><style>
    :root{--b:#0b5cad;--g:#16a34a;--r:#dc2626;--bg:#f3f5f8;--ink:#1d2430}
    *{box-sizing:border-box}body{margin:0;font:15px/1.45 "Segoe UI",Roboto,Arial,sans-serif;background:' . ($print ? '#fff' : 'var(--bg)') . ';color:var(--ink)}
    nav{display:flex;flex-wrap:wrap;gap:4px 14px;align-items:center;background:var(--b);color:#fff;padding:10px 16px}nav a{color:#dfe9f7;text-decoration:none}nav a.on{color:#fff;font-weight:700;border-bottom:2px solid #fff}
    .rt{margin-left:auto;font-size:13px;padding:2px 8px;border-radius:10px}.rt.ok{background:#15803d}.rt.bad{background:#b91c1c}
    .box{background:#fff;margin:14px 16px;padding:14px 16px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.08);overflow-x:auto}.narrow{max-width:460px;margin:30px auto}
    h2{margin:0 0 10px;font-size:18px}label{display:block;margin:10px 0 4px;font-weight:600}
    input,select{font:inherit;padding:8px;border:1px solid #c5ccd6;border-radius:5px;width:100%}form.inline{display:flex;gap:8px;flex-wrap:wrap}form.inline input,form.inline select{width:auto}
    button{font:inherit;background:var(--b);color:#fff;border:0;border-radius:5px;padding:8px 16px;margin-top:10px;cursor:pointer}button.g{background:var(--g)}button.r{background:var(--r)}
    table{border-collapse:collapse;width:100%;font-size:14px}th,td{border-bottom:1px solid #e3e7ec;padding:6px 8px;text-align:left;vertical-align:top}
    td.act form{display:inline-flex;gap:4px;align-items:center;margin-right:6px}td.act button{margin:0}td.act input{width:130px;padding:6px}td.sms{max-width:420px;white-space:pre-wrap}
    .flash{background:#e8f5e9;border:1px solid #a5d6a7;margin:12px 16px;padding:8px 12px;border-radius:6px}.err{color:var(--r);font-weight:600}.hint{color:#555;font-size:14px}
    .cards{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;padding:6px}.card{border:1px dashed #333;padding:8px;text-align:center;page-break-inside:avoid}
    .card .bn{font-weight:800}.card .cd{font:700 22px Consolas,monospace;letter-spacing:2px;margin:4px 0}.card .ft{font-size:10px}
    @media print{.cards{grid-template-columns:repeat(4,1fr)}}
    </style></head><body>';
}
function page_foot(): void { echo '</body></html>'; }
