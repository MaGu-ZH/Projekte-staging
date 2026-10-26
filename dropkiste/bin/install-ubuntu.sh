#!/usr/bin/env bash
# Run on the intended Ubuntu server as root, from an unpacked release.
set -euo pipefail
[ "$(id -u)" = 0 ] || { echo 'Bitte als root ausführen.'; exit 1; }
command -v php >/dev/null || { echo 'PHP muss bereits installiert sein (siehe README).'; exit 1; }
SOURCE_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
DOMAIN=dropkiste.app
if [ ! -s /etc/letsencrypt/live/$DOMAIN/fullchain.pem ]; then
  echo 'Das bestehende HTTPS-Zertifikat für dropkiste.app fehlt. Bitte zuerst HTTPS einrichten.'
  exit 1
fi
if [ -e /etc/dropkiste/config.php ]; then
  echo 'Dropkiste-Konfiguration existiert bereits. Für Updates siehe README; nichts wurde geändert.'
  exit 1
fi
MIN_PASSWORD_LENGTH=$(php -r '$c=require $argv[1]; echo $c["min_admin_password_length"];' "$SOURCE_DIR/config.example.php")
read -r -s -p "Neues Verwaltungspasswort (mindestens $MIN_PASSWORD_LENGTH Zeichen): " ADMIN_PASSWORD; echo
read -r -s -p 'Passwort wiederholen: ' ADMIN_CONFIRM; echo
[ ${#ADMIN_PASSWORD} -ge "$MIN_PASSWORD_LENGTH" ] && [ "$ADMIN_PASSWORD" = "$ADMIN_CONFIRM" ] || { echo 'Passwörter stimmen nicht überein oder sind zu kurz.'; exit 1; }
apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y apache2 php libapache2-mod-php php-sqlite3 php-curl
PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
php -r 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);'
RELEASE_DIR=/opt/dropkiste-app/releases/$(date -u +%Y%m%dT%H%M%SZ)
install -d -m 755 "$RELEASE_DIR/public" "$RELEASE_DIR/bin"
install -m 644 "$SOURCE_DIR"/{app.php,users-api.php,delivery.php} "$RELEASE_DIR/"
install -m 644 "$SOURCE_DIR"/public/{index.html,api.php,app.js,style.css,box.svg} "$RELEASE_DIR/public/"
install -m 644 "$SOURCE_DIR/bin/cleanup.php" "$RELEASE_DIR/bin/cleanup.php"
install -d -m 750 -o root -g www-data /etc/dropkiste
install -d -m 700 -o www-data -g www-data /var/lib/dropkiste
CONFIG_TMP=$(mktemp /etc/dropkiste/.config.XXXXXX)
trap 'rm -f "$CONFIG_TMP"' EXIT
# The password travels through stdin, never command arguments or an environment value.
printf '%s' "$ADMIN_PASSWORD" | php -r '
$c=require $argv[1];
$c["storage_dir"]="/var/lib/dropkiste";
$c["admin_password_hash"]=password_hash(stream_get_contents(STDIN),PASSWORD_DEFAULT);
echo "<?php\nreturn ".var_export($c,true).";\n";
' "$SOURCE_DIR/config.example.php" > "$CONFIG_TMP"
unset ADMIN_PASSWORD ADMIN_CONFIRM
chown root:www-data "$CONFIG_TMP"; chmod 640 "$CONFIG_TMP"
mv "$CONFIG_TMP" /etc/dropkiste/config.php
printf '%s\n' '<?php return require "/etc/dropkiste/config.php";' > "$RELEASE_DIR/config.php"
chmod 644 "$RELEASE_DIR/config.php"
# Initialize the DB and verify storage access as the actual webserver user.
runuser -u www-data -- php "$RELEASE_DIR/bin/cleanup.php"
# Server-side bounds must agree with max_file_bytes in /etc/dropkiste/config.php.
UPLOAD_BYTES=$(php -r '$c=require "/etc/dropkiste/config.php";echo $c["max_file_bytes"];')
POST_BYTES=$((UPLOAD_BYTES + 5 * 1024 * 1024))
CLEANUP_SECONDS=$(php -r '$c=require "/etc/dropkiste/config.php";echo $c["cleanup_interval_seconds"];')
cat > "/etc/php/$PHP_VERSION/apache2/conf.d/99-dropkiste.ini" <<INI
upload_max_filesize=$UPLOAD_BYTES
post_max_size=$POST_BYTES
max_file_uploads=1
max_input_time=300
max_execution_time=300
memory_limit=128M
display_errors=Off
log_errors=On
session.use_strict_mode=1
session.cookie_httponly=1
session.cookie_secure=1
session.cookie_samesite=Strict
INI
BACKUP_DIR=/root/dropkiste-backup-$(date -u +%Y%m%dT%H%M%SZ)
install -d -m 700 "$BACKUP_DIR"
for f in dropkiste.conf dropkiste-le-ssl.conf; do
  if [ -f "/etc/apache2/sites-available/$f" ]; then cp -a "/etc/apache2/sites-available/$f" "$BACKUP_DIR/"; fi
done
write_site() {
  local port=$1
  local output=$2
  cat > "$output" <<CONF
<VirtualHost *:$port>
    ServerName $DOMAIN
    DocumentRoot $RELEASE_DIR/public
    <Directory $RELEASE_DIR/public>
        Options -Indexes
        AllowOverride None
        Require all granted
        DirectoryIndex index.html
    </Directory>
    <FilesMatch "^\\.">
        Require all denied
    </FilesMatch>
    LimitRequestBody $POST_BYTES
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "no-referrer"
    Header always set X-Frame-Options "DENY"
    Header always set Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'"
    ErrorLog \${APACHE_LOG_DIR}/dropkiste-error.log
    CustomLog \${APACHE_LOG_DIR}/dropkiste-access.log combined
CONF
  if [ "$port" = 443 ]; then
    cat >> "$output" <<CONF
    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/$DOMAIN/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/$DOMAIN/privkey.pem
    Include /etc/letsencrypt/options-ssl-apache.conf
CONF
  else
    echo "    Redirect permanent / https://$DOMAIN/" >> "$output"
  fi
  echo '</VirtualHost>' >> "$output"
}
a2enmod headers ssl
write_site 80 /etc/apache2/sites-available/dropkiste.conf
write_site 443 /etc/apache2/sites-available/dropkiste-le-ssl.conf
a2ensite dropkiste.conf dropkiste-le-ssl.conf
if ! apache2ctl configtest; then
  cp -a "$BACKUP_DIR/"*.conf /etc/apache2/sites-available/
  echo "Apache-Konfiguration zurückgesetzt. Sicherung: $BACKUP_DIR"
  exit 1
fi
cat > /etc/systemd/system/dropkiste-cleanup.service <<UNIT
[Unit]
Description=Dropkiste expired file cleanup
[Service]
Type=oneshot
User=www-data
Group=www-data
UMask=0077
ExecStart=/usr/bin/php $RELEASE_DIR/bin/cleanup.php
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=/var/lib/dropkiste
UNIT
cat > /etc/systemd/system/dropkiste-cleanup.timer <<UNIT
[Unit]
Description=Delete expired Dropkiste files every minute
[Timer]
OnBootSec=30s
OnUnitActiveSec=${CLEANUP_SECONDS}s
Persistent=true
[Install]
WantedBy=timers.target
UNIT
systemctl daemon-reload
systemctl enable --now dropkiste-cleanup.timer
systemctl restart apache2
curl --noproxy '*' --fail --silent --show-error --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN/api.php?action=session" > /dev/null
echo "Dropkiste installiert: https://$DOMAIN"
echo "Vorherige Apache-Konfiguration: $BACKUP_DIR"
echo 'Die bisherige Gesundheitsapp und deren Datenbank wurden nicht gelöscht.'
