<?php
// Router imeshaweka vocha: sasa mteja anaweza kuingia.
require __DIR__ . '/../lib.php';
header('Content-Type: text/plain');
if (!vw_router_key_ok()) { http_response_code(403); exit('forbidden'); }
$id = (int)($_GET['id'] ?? 0);
vw_db()->prepare("UPDATE orders SET status = 'active', updated_at = ? WHERE id = ? AND status = 'approved'")->execute([vw_now(), $id]);
echo 'ok';
