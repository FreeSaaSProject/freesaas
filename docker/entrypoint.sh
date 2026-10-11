#!/bin/bash
set -euo pipefail

CFG=/var/www/html/cfg/config.ini
EXAMPLE=/var/www/html/cfg/config.example.ini

if [[ ! -f "$CFG" ]]; then
  if [[ -f "$EXAMPLE" ]]; then
    cp "$EXAMPLE" "$CFG"
  else
    echo "Missing config.example.ini" >&2
    exit 1
  fi
fi

# Point at compose MySQL when example defaults are present
sed -i \
  -e 's/host="127.0.0.1"/host="db"/' \
  -e 's/host="localhost"/host="db"/' \
  -e 's/user_db="root"/user_db="freesaas"/' \
  -e 's/pass_db="secret"/pass_db="freesaas"/' \
  -e 's/dbase="likespa"/dbase="freesaas"/' \
  -e 's/CHANGE_ME_COOKIE_SALT/docker-demo-cookie-salt-change-me/' \
  -e 's/CHANGE_ME_SITE_SALT/docker-demo-site-salt-change-me/' \
  "$CFG"

chown -R www-data:www-data /var/www/html/cfg 2>/dev/null || true

exec apache2-foreground
