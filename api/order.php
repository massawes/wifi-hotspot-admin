<?php
// Mteja: POST phone, package, pay_ref (namba ya muamala kutoka SMS ya malipo), mac
require __DIR__ . '/../lib.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') vw_json(['error' => 'POST tu'], 405);
$phone = preg_replace('/\D/', '', (string)($_POST['phone'] ?? ''));
$pkg   = (string)($_POST['package'] ?? '');
$ref   = vw_norm_ref((string)($_POST['pay_ref'] ?? ''));
$mac   = substr(preg_replace('/[^0-9A-Fa-f:]/', '', (string)($_POST['mac'] ?? '')), 0, 17);
if (!preg_match('/^0[67]\d{8}$/', $phone)) vw_json(['error' => 'Namba ya simu si sahihi. Mfano: 0712345678'], 422);
if (!isset(VW_PACKAGES[$pkg])) vw_json(['error' => 'Chagua kifurushi sahihi.'], 422);
if (strlen($ref) < 6 || strlen($ref) > 30) vw_json(['error' => 'Andika namba ya muamala (Transaction ID) kutoka SMS ya malipo.'], 422);
$db = vw_db();
// Muamala mmoja = vocha moja tu.
$st = $db->prepare('SELECT id, status, phone FROM orders WHERE pay_ref = ?');
$st->execute([$ref]);
if ($old = $st->fetch()) {
    if ($old['phone'] === $phone) vw_json(['id' => (int)$old['id'], 'status' => $old['status']]); // mteja yule yule anajaribu tena
    vw_json(['error' => 'Namba hii ya muamala imeshatumika.'], 409);
}
// Kuzuia usumbufu: oda zisizozidi 5 zinazosubiri kwa namba moja.
$st = $db->prepare("SELECT COUNT(*) FROM orders WHERE phone = ? AND status = 'pending'");
$st->execute([$phone]);
if ((int)$st->fetchColumn() >= 5) vw_json(['error' => 'Una maombi mengi yanayosubiri. Piga simu kwa msaada.'], 429);
$now = vw_now();
$db->prepare("INSERT INTO orders (kind, phone, pay_ref, package, amount, status, mac, created_at, updated_at) VALUES ('order', ?, ?, ?, ?, 'pending', ?, ?, ?)")
   ->execute([$phone, $ref, $pkg, VW_PACKAGES[$pkg]['price'], $mac, $now, $now]);
$id = (int)$db->lastInsertId();
vw_match_order($id); // kama SMS ya malipo ilishafika, idhinisha sasa hivi
vw_json(['id' => $id, 'status' => 'pending']);
