#!/bin/bash
# Install on Ubuntu + Apache + PHP 8 (pdo_sqlite). Run as root from the repo folder.
set -e
T=${1:-/var/www/html/wifi-hotspot-admin}
DATA=/var/lib/vicentwifi
mkdir -p "$T" && cp -r index.php lib.php api admin "$T"/
chown -R root:www-data "$T" && find "$T" -type d -exec chmod 755 {} + && find "$T" -type f -exec chmod 644 {} +
mkdir -p $DATA && chown www-data:www-data $DATA && chmod 750 $DATA
if [ ! -f $DATA/secrets.php ]; then
  printf "<?php return ['ROUTER_KEY' => '%s', 'SMS_KEY' => '%s', 'SETUP_CODE' => '%s'];\n" \
    "$(openssl rand -hex 16)" "$(openssl rand -hex 16)" "$(openssl rand -hex 4 | tr a-f A-F)" > $DATA/secrets.php
  chown root:www-data $DATA/secrets.php && chmod 640 $DATA/secrets.php
fi
echo "Installed to $T. Open /admin/ and use SETUP_CODE from $DATA/secrets.php to create the first admin."
