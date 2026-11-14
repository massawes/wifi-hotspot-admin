<?php
// Mteja anauliza kila sekunde chache. Vocha inatolewa ikishawekwa kwenye router (active).
require __DIR__ . '/../lib.php';
$id = (int)($_GET['id'] ?? 0);
$phone = preg_replace('/\D/', '', (string)($_GET['phone'] ?? ''));
$st = vw_db()->prepare("SELECT status, username, password, note FROM orders WHERE id = ? AND phone = ? AND kind = 'order'");
$st->execute([$id, $phone]);
$o = $st->fetch();
if (!$o) vw_json(['error' => 'Ombi halipo'], 404);
if ($o['status'] === 'active') vw_json(['status' => 'active', 'username' => $o['username'], 'password' => $o['password']]);
if ($o['status'] === 'rejected') vw_json(['status' => 'rejected', 'error' => $o['note'] ?: 'Malipo hayakuthibitishwa.']);
vw_json(['status' => $o['status']]); // pending | approved (inasubiri router)
