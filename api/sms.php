<?php
// Malipo ya AUTOMATIC: app ya "SMS forwarder" kwenye simu ya kupokea malipo inatuma kila SMS ya malipo hapa.
// URL: .../api/sms.php?key=SMS_KEY   (POST: JSON {"from","text"} au fomu from/text/message/body)
require __DIR__ . '/../lib.php';
$k = vw_secrets()['SMS_KEY'] ?? '';
if ($k === '' || !hash_equals($k, (string)($_GET['key'] ?? ''))) vw_json(['error' => 'forbidden'], 403);
$raw = file_get_contents('php://input');
$j = json_decode($raw, true);
$in = is_array($j) ? $j : $_POST;
$body = '';
foreach (['text', 'message', 'body', 'msg', 'content'] as $f) if (!empty($in[$f]) && is_string($in[$f])) { $body = $in[$f]; break; }
if ($body === '' && is_string($raw)) $body = $raw;
$from = '';
foreach (['from', 'sender', 'phone', 'number'] as $f) if (!empty($in[$f]) && is_string($in[$f])) { $from = $in[$f]; break; }
$body = mb_substr(trim($body), 0, 2000);
if ($body === '') vw_json(['error' => 'SMS tupu'], 422);
$db = vw_db();
$db->prepare('INSERT INTO sms (sender, body, created_at) VALUES (?, ?, ?)')->execute([mb_substr($from, 0, 60), $body, vw_now()]);
$matched = vw_match_sms((int)$db->lastInsertId());
vw_json(['ok' => true, 'matched_order' => $matched]);
