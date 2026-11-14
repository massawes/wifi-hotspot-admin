<?php
// Router (scheduler) inachukua vocha moja iliyoidhinishwa: "id,username,password,profile" au "none".
require __DIR__ . '/../lib.php';
header('Content-Type: text/plain'); header('Cache-Control: no-store');
if (!vw_router_key_ok()) { http_response_code(403); exit('forbidden'); }
$db = vw_db();
$db->prepare('INSERT OR REPLACE INTO router_seen (id, at) VALUES (1, ?)')->execute([vw_now()]);
$o = $db->query("SELECT id, username, password, package FROM orders WHERE status = 'approved' ORDER BY id LIMIT 1")->fetch();
echo $o ? "{$o['id']},{$o['username']},{$o['password']},{$o['package']}" : 'none';
