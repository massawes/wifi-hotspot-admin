<?php
// Ukurasa wa wateja unasoma jina, namba ya malipo na vifurushi hapa.
require __DIR__ . '/../lib.php';
$p = [];
foreach (VW_PACKAGES as $k => $v) $p[] = ['key' => $k, 'label' => $v['label'], 'price' => $v['price']];
vw_json([
    'business_name' => vw_setting('business_name'),
    'pay_number'    => vw_setting('pay_number'),
    'pay_name'      => vw_setting('pay_name'),
    'support_phone' => vw_setting('support_phone'),
    'packages'      => $p,
]);
