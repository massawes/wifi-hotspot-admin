<?php
/**
 * WiFi Hotspot Admin - orders, payments (manual + SMS auto-match) and vouchers for a MikroTik hotspot.
 * Data (SQLite) lives OUTSIDE the web root (default /var/lib/vicentwifi, override with env VW_DATA_DIR).
 */
declare(strict_types=1);
date_default_timezone_set('Africa/Dar_es_Salaam');

define('VW_DATA', getenv('VW_DATA_DIR') ?: '/var/lib/vicentwifi');
const VW_SECRETS = VW_DATA . '/secrets.php';   // ROUTER_KEY, SMS_KEY, SETUP_CODE (yanatengenezwa na install)

const VW_PACKAGES = [
    'SIKU1-500'    => ['label' => 'SIKU 1',  'price' => 500],
    'SIKU2-1000'   => ['label' => 'SIKU 2',  'price' => 1000],
    'WIKI1-3000'   => ['label' => 'WIKI 1',  'price' => 3000],
    'MWEZI1-10000' => ['label' => 'MWEZI 1', 'price' => 10000],
];

const VW_DEFAULT_SETTINGS = [
    // Example values only: the real values are saved from Admin > Mipangilio (settings table).
    'business_name' => 'MY WIFI',
    'pay_number'    => '07XXXXXXXX',
    'pay_name'      => 'BUSINESS OWNER NAME',
    'support_phone' => '07XXXXXXXX',
];

function vw_secrets(): array
{
    static $s = null;
    if ($s === null) {
        $s = is_file(VW_SECRETS) ? (require VW_SECRETS) : [];
    }
    return $s;
}

function vw_db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . VW_DATA . '/wifi.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        kind TEXT NOT NULL DEFAULT 'order',          -- order (mteja) | voucher (admin)
        phone TEXT, pay_ref TEXT, package TEXT NOT NULL, amount INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending',      -- pending | approved | active | rejected
        username TEXT, password TEXT, batch TEXT,
        approved_by TEXT, note TEXT, mac TEXT,
        created_at TEXT NOT NULL, updated_at TEXT NOT NULL
      );
      CREATE UNIQUE INDEX IF NOT EXISTS u_pay_ref ON orders(pay_ref) WHERE pay_ref IS NOT NULL AND pay_ref <> '';
      CREATE UNIQUE INDEX IF NOT EXISTS u_username ON orders(username) WHERE username IS NOT NULL;
      CREATE INDEX IF NOT EXISTS i_status ON orders(status);
      CREATE TABLE IF NOT EXISTS sms (
        id INTEGER PRIMARY KEY AUTOINCREMENT, sender TEXT, body TEXT NOT NULL,
        order_id INTEGER, created_at TEXT NOT NULL
      );
      CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT NOT NULL);
      CREATE TABLE IF NOT EXISTS admins (
        id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE NOT NULL, pass_hash TEXT NOT NULL, created_at TEXT NOT NULL
      );
      CREATE TABLE IF NOT EXISTS router_seen (id INTEGER PRIMARY KEY CHECK (id = 1), at TEXT NOT NULL);
    ");
    return $pdo;
}

function vw_now(): string { return date('Y-m-d H:i:s'); }

function vw_setting(string $k): string
{
    $st = vw_db()->prepare('SELECT v FROM settings WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? (VW_DEFAULT_SETTINGS[$k] ?? '') : (string)$v;
}

function vw_json($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Access-Control-Allow-Origin: *');   // ukurasa wa wateja uko kwenye router
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function vw_e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** Namba ya vocha: tarakimu 6 + herufi, rahisi kuandika. Username = password. */
function vw_code(): string
{
    $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    do {
        $c = '';
        for ($i = 0; $i < 6; $i++) $c .= $chars[random_int(0, strlen($chars) - 1)];
        $st = vw_db()->prepare('SELECT 1 FROM orders WHERE username = ?');
        $st->execute([$c]);
    } while ($st->fetchColumn());
    return $c;
}

/** Idhinisha oda: tengeneza vocha; router itaichukua (router_pull) ndani ya sekunde chache. */
function vw_approve(int $id, string $by): bool
{
    $db = vw_db();
    $db->beginTransaction();
    $st = $db->prepare("SELECT * FROM orders WHERE id = ? AND status = 'pending'");
    $st->execute([$id]);
    $o = $st->fetch();
    if (!$o) { $db->rollBack(); return false; }
    $code = vw_code();
    $db->prepare("UPDATE orders SET status='approved', username=?, password=?, approved_by=?, updated_at=? WHERE id=? AND status='pending'")
       ->execute([$code, $code, $by, vw_now(), $id]);
    $db->commit();
    return true;
}

/** Herufi/tarakimu tu, herufi kubwa: "ab12-cd 34" -> "AB12CD34" */
function vw_norm_ref(string $s): string { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $s)); }

/** Kiasi kikubwa zaidi kilichotajwa kwenye SMS (mfano "Tsh 1,000.00" -> 1000). */
function vw_sms_amounts(string $body): array
{
    preg_match_all('/(?:TSH|TZS|SH)\.?\s*([0-9][0-9,]*)(?:\.\d+)?/i', $body, $m);
    return array_map(fn($x) => (int)str_replace(',', '', $x), $m[1] ?? []);
}

/**
 * Malipo ya automatic: SMS ya malipo ikiwa na namba ya muamala (pay_ref) ya oda
 * na kiasi kisichopungua bei, oda inaidhinishwa yenyewe.
 */
function vw_match_sms(int $smsId): ?int
{
    $db = vw_db();
    $s = $db->query('SELECT * FROM sms WHERE id = ' . $smsId)->fetch();
    if (!$s || $s['order_id']) return null;
    $text = vw_norm_ref($s['body']);
    $amounts = vw_sms_amounts($s['body']);
    $orders = $db->query("SELECT * FROM orders WHERE status='pending' AND kind='order' AND pay_ref <> '' AND created_at >= datetime('now','localtime','-2 days')")->fetchAll();
    foreach ($orders as $o) {
        if (strlen($o['pay_ref']) >= 6 && strpos($text, $o['pay_ref']) !== false) {
            $ok = false;
            foreach ($amounts as $a) if ($a >= (int)$o['amount']) $ok = true;
            if (!$ok) continue;
            if (vw_approve((int)$o['id'], 'SMS-auto')) {
                $db->prepare('UPDATE sms SET order_id=? WHERE id=?')->execute([$o['id'], $smsId]);
                return (int)$o['id'];
            }
        }
    }
    return null;
}

/** Oda mpya: angalia kama SMS yake ilishafika kabla. */
function vw_match_order(int $orderId): void
{
    $db = vw_db();
    foreach ($db->query("SELECT id FROM sms WHERE order_id IS NULL AND created_at >= datetime('now','localtime','-2 days') ORDER BY id DESC")->fetchAll() as $s) {
        if (vw_match_sms((int)$s['id']) === $orderId) return;
    }
}

function vw_router_key_ok(): bool
{
    $k = vw_secrets()['ROUTER_KEY'] ?? '';
    return $k !== '' && hash_equals($k, (string)($_GET['key'] ?? ''));
}
