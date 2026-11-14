<?php
// Customer hotspot on ether3/4/5 (bridge-hotspot, 10.10.20.0/24). Office LAN ether2 untouched.
// Idempotent: each item is added only when missing.
// Usage: ROUTER_IP=192.168.88.1 RU=admin RP=secret ROUTEROS_API=/path/to/routeros_api.class.php php tools/router_setup.php
// (routeros_api.class.php = the RouterOS PHP API class shipped with Mikhmon v3)
require getenv('ROUTEROS_API') ?: __DIR__ . '/routeros_api.class.php';
$api = new RouterosAPI();
$api->connect(getenv('ROUTER_IP') ?: '192.168.88.1', getenv('RU'), getenv('RP')) or die("LOGIN FAILED\n");

function find($api, $path, $q) { $r = $api->comm("$path/print", $q); return $r[0] ?? null; }
function ensure($api, $path, $q, $add, $label) {
    if (find($api, $path, $q)) { echo "ok (exists): $label\n"; return; }
    $r = $api->comm("$path/add", $add);
    if (isset($r['!trap'])) { echo "ERROR $label: ", json_encode($r['!trap']), "\n"; exit(1); }
    echo "added: $label\n";
}

ensure($api, '/interface/bridge', ['?name' => 'bridge-hotspot'], ['name' => 'bridge-hotspot', 'comment' => 'WATEJA WIFI'], 'bridge-hotspot');
foreach (['ether3', 'ether4', 'ether5'] as $p) {
    ensure($api, '/interface/bridge/port', ['?interface' => $p], ['bridge' => 'bridge-hotspot', 'interface' => $p], "port $p");
}
ensure($api, '/ip/address', ['?address' => '10.10.20.1/24'], ['address' => '10.10.20.1/24', 'interface' => 'bridge-hotspot', 'comment' => 'WATEJA WIFI'], 'ip 10.10.20.1/24');
ensure($api, '/ip/pool', ['?name' => 'hs-pool'], ['name' => 'hs-pool', 'ranges' => '10.10.20.10-10.10.20.250'], 'pool hs-pool');
ensure($api, '/ip/dhcp-server', ['?name' => 'dhcp-hotspot'], ['name' => 'dhcp-hotspot', 'interface' => 'bridge-hotspot', 'address-pool' => 'hs-pool', 'lease-time' => '1h', 'disabled' => 'no'], 'dhcp-hotspot');
ensure($api, '/ip/dhcp-server/network', ['?address' => '10.10.20.0/24'], ['address' => '10.10.20.0/24', 'gateway' => '10.10.20.1', 'dns-server' => '10.10.20.1'], 'dhcp network');

// DNS for hotspot clients, but never answer DNS from the internet side.
$api->comm('/ip/dns/set', ['allow-remote-requests' => 'yes', 'servers' => '8.8.8.8,1.1.1.1']);
echo "dns: remote requests on\n";
foreach (['pppoe-out1', 'ether1wan'] as $wan) foreach (['udp', 'tcp'] as $pr) {
    ensure($api, '/ip/firewall/filter', ['?comment' => "block dns $wan $pr"],
        ['chain' => 'input', 'in-interface' => $wan, 'protocol' => $pr, 'dst-port' => '53', 'action' => 'drop', 'comment' => "block dns $wan $pr"], "fw dns $wan $pr");
}

// Clock (vifurushi vinategemea saa sahihi)
$api->comm('/system/clock/set', ['time-zone-name' => 'Africa/Dar_es_Salaam']);
$api->comm('/system/ntp/client/set', ['enabled' => 'yes', 'primary-ntp' => '162.159.200.123', 'secondary-ntp' => '216.239.35.0']);
echo "clock: Africa/Dar_es_Salaam + NTP\n";

ensure($api, '/ip/hotspot/profile', ['?name' => 'hsprof-wateja'],
    ['name' => 'hsprof-wateja', 'hotspot-address' => '10.10.20.1', 'html-directory' => 'flash/hotspot', 'login-by' => 'http-chap,cookie', 'dns-name' => 'login.wifi'], 'hotspot profile');
ensure($api, '/ip/hotspot', ['?name' => 'hotspot-wateja'],
    ['name' => 'hotspot-wateja', 'interface' => 'bridge-hotspot', 'address-pool' => 'none', 'profile' => 'hsprof-wateja', 'addresses-per-mac' => '2', 'disabled' => 'no'], 'hotspot server');

// Vifurushi (Mikhmon "remc": muda unaanza mteja akiingia mara ya kwanza, kisha anaondolewa; mauzo yanarekodiwa)
$packages = [
    ['SIKU1-500',   '500',   '1d',  '2M/2M'],
    ['SIKU2-1000',  '1000',  '2d',  '2M/2M'],
    ['WIKI1-3000',  '3000',  '7d',  '3M/3M'],
    ['MWEZI1-10000','10000', '30d', '3M/3M'],
];
foreach ($packages as [$name, $price, $validity, $rate]) {
    $record = '; :local mac $"mac-address"; :local time [/system clock get time ]; /system script add name="$date-|-$time-|-$user-|-' . $price . '-|-$address-|-$mac-|-' . $validity . '-|-' . $name . '-|-$comment" owner="$month$year" source="$date" comment="mikhmon"';
    $lock = '; [:local mac $"mac-address"; /ip hotspot user set mac-address=$mac [find where name=$user]]';
    $onlogin = ':put (",remc,' . $price . ',' . $validity . ',' . $price . ',,Enable,"); {:local comment [ /ip hotspot user get [/ip hotspot user find where name="$user"] comment]; :local ucode [:pic $comment 0 2]; :if ($ucode = "vc" or $ucode = "up" or $comment = "") do={ :local date [ /system clock get date ];:local year [ :pick $date 7 11 ];:local month [ :pick $date 0 3 ]; /sys sch add name="$user" disable=no start-date=$date interval="' . $validity . '"; :delay 5s; :local exp [ /sys sch get [ /sys sch find where name="$user" ] next-run]; :local getxp [len $exp]; :if ($getxp = 15) do={ :local d [:pic $exp 0 6]; :local t [:pic $exp 7 16]; :local s ("/"); :local exp ("$d$s$year $t"); /ip hotspot user set comment="$exp" [find where name="$user"];}; :if ($getxp = 8) do={ /ip hotspot user set comment="$date $exp" [find where name="$user"];}; :if ($getxp > 15) do={ /ip hotspot user set comment="$exp" [find where name="$user"];};:delay 5s; /sys sch remove [find where name="$user"]' . $record . $lock . '}}';
    $bg = ':local dateint do={:local montharray ( "jan","feb","mar","apr","may","jun","jul","aug","sep","oct","nov","dec" );:local days [ :pick $d 4 6 ];:local month [ :pick $d 0 3 ];:local year [ :pick $d 7 11 ];:local monthint ([ :find $montharray $month]);:local month ($monthint + 1);:if ( [len $month] = 1) do={:local zero ("0");:return [:tonum ("$year$zero$month$days")];} else={:return [:tonum ("$year$month$days")];}}; :local timeint do={ :local hours [ :pick $t 0 2 ]; :local minutes [ :pick $t 3 5 ]; :return ($hours * 60 + $minutes) ; }; :local date [ /system clock get date ]; :local time [ /system clock get time ]; :local today [$dateint d=$date] ; :local curtime [$timeint t=$time] ; :foreach i in [ /ip hotspot user find where profile="' . $name . '" ] do={ :local comment [ /ip hotspot user get $i comment]; :local name [ /ip hotspot user get $i name]; :local gettime [:pic $comment 12 20]; :if ([:pic $comment 3] = "/" and [:pic $comment 6] = "/") do={:local expd [$dateint d=$comment] ; :local expt [$timeint t=$gettime] ; :if (($expd < $today and $expt < $curtime) or ($expd < $today and $expt > $curtime) or ($expd = $today and $expt < $curtime)) do={ [ /ip hotspot user remove $i ]; [ /ip hotspot active remove [find where user=$name] ];}}}';
    ensure($api, '/ip/hotspot/user/profile', ['?name' => $name],
        ['name' => $name, 'rate-limit' => $rate, 'shared-users' => '1', 'status-autorefresh' => '1m', 'on-login' => $onlogin], "user profile $name");
    ensure($api, '/system/scheduler', ['?name' => $name],
        ['name' => $name, 'start-time' => '0' . rand(1, 5) . ':' . rand(10, 59) . ':' . rand(10, 59), 'interval' => '00:02:' . rand(10, 59), 'on-event' => $bg, 'disabled' => 'no', 'comment' => "Monitor Profile $name"], "scheduler $name");
}
$api->disconnect();
echo "DONE\n";
