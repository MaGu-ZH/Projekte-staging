#!/usr/bin/env bash
set -euo pipefail
[ "$(id -u)" = 0 ] || { echo 'Bitte als root ausführen.'; exit 1; }
SOURCE_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
read -r -p 'SMTP-Server (aus den Postfach-Einstellungen): ' SMTP_HOST
read -r -p 'SMTP-Port [587]: ' SMTP_PORT
SMTP_PORT=${SMTP_PORT:-587}
read -r -p 'SMTP-Benutzername: ' SMTP_USER
read -r -p 'Absender-E-Mail-Adresse: ' SMTP_FROM
read -r -s -p 'SMTP-/Postfach-Passwort: ' SMTP_PASSWORD; echo
printf '%s\0' "$SMTP_HOST" "$SMTP_PORT" "$SMTP_USER" "$SMTP_PASSWORD" "$SMTP_FROM" | php "$SOURCE_DIR/bin/configure-mail.php"
unset SMTP_PASSWORD
systemctl restart apache2
echo 'Bitte zunächst eine harmlose Einladung an die eigene Adresse versenden und den Eingang prüfen.'
