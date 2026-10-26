#!/usr/bin/env bash
set -euo pipefail
[ "$(id -u)" = 0 ] || { echo 'Bitte als root ausführen.'; exit 1; }
SOURCE_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
[ -f /etc/dropkiste/config.php ] || { echo 'Bestehende Dropkiste-Konfiguration fehlt.'; exit 1; }
OLD_PUBLIC=$(awk '$1=="DocumentRoot" {print $2;exit}' /etc/apache2/sites-available/dropkiste-le-ssl.conf)
case "$OLD_PUBLIC" in /opt/dropkiste-app/releases/*/public) ;; *) echo 'Unbekannter Apache-Pfad. Update gestoppt.'; exit 1;; esac
[ -f "$OLD_PUBLIC/api.php" ] || { echo 'Bisherige Installation fehlt.'; exit 1; }
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
BACKUP_DIR=/root/dropkiste-update-backup-$STAMP
RELEASE_DIR=/opt/dropkiste-app/releases/$STAMP
install -d -m 700 "$BACKUP_DIR"
cp -a /etc/dropkiste/config.php "$BACKUP_DIR/config.php"
cp -a /etc/apache2/sites-available/dropkiste{,-le-ssl}.conf "$BACKUP_DIR/"
cp -a /etc/systemd/system/dropkiste-cleanup.service "$BACKUP_DIR/"
# Consistent online SQLite backup. Upload files stay in their existing location.
DB_BACKUP_TEMP=$(mktemp /var/lib/dropkiste/.update-backup.XXXXXX)
trap 'rm -f "$DB_BACKUP_TEMP"' EXIT
chown www-data:www-data "$DB_BACKUP_TEMP"
runuser -u www-data -- php -r '
$c=require "/etc/dropkiste/config.php";
$s=new SQLite3($c["storage_dir"]."/dropkiste.sqlite",SQLITE3_OPEN_READONLY);$s->busyTimeout(10000);
$d=new SQLite3($argv[1]);if(!$s->backup($d))exit(1);$d->close();$s->close();
' "$DB_BACKUP_TEMP"
mv "$DB_BACKUP_TEMP" "$BACKUP_DIR/database.sqlite"
chown root:root "$BACKUP_DIR/database.sqlite"
chmod 600 "$BACKUP_DIR/database.sqlite"
apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y php-curl php-sqlite3
install -d -m 755 "$RELEASE_DIR/public" "$RELEASE_DIR/bin"
install -m 644 "$SOURCE_DIR"/{app.php,users-api.php,delivery.php} "$RELEASE_DIR/"
install -m 644 "$SOURCE_DIR"/public/{index.html,api.php,app.js,style.css,box.svg} "$RELEASE_DIR/public/"
install -m 644 "$SOURCE_DIR/bin/cleanup.php" "$RELEASE_DIR/bin/cleanup.php"
printf '%s\n' '<?php return require "/etc/dropkiste/config.php";' > "$RELEASE_DIR/config.php"
chmod 644 "$RELEASE_DIR/config.php"
# Additive migration assigns all existing transfers to the original administrator.
runuser -u www-data -- php "$RELEASE_DIR/bin/cleanup.php"
for SITE in /etc/apache2/sites-available/dropkiste{,-le-ssl}.conf; do
  sed -i "s|$OLD_PUBLIC|$RELEASE_DIR/public|g" "$SITE"
done
sed -i "s|^ExecStart=.*|ExecStart=/usr/bin/php $RELEASE_DIR/bin/cleanup.php|" /etc/systemd/system/dropkiste-cleanup.service
if ! apache2ctl configtest; then
  cp -a "$BACKUP_DIR/"*.conf /etc/apache2/sites-available/
  cp -a "$BACKUP_DIR/dropkiste-cleanup.service" /etc/systemd/system/
  echo "Apache-Konfiguration zurückgesetzt. Sicherung: $BACKUP_DIR"
  exit 1
fi
systemctl daemon-reload
systemctl restart dropkiste-cleanup.timer
systemctl restart apache2
curl --noproxy '*' --fail --silent --show-error --resolve dropkiste.app:443:127.0.0.1 'https://dropkiste.app/api.php?action=session' > /dev/null
echo "Update installiert. Sicherung: $BACKUP_DIR"
echo 'Anmeldung als admin mit deinem bisherigen Verwaltungspasswort.'
echo 'E-Mail/SMS bleiben deaktiviert, bis du die Versandkonfiguration aktivierst.'
