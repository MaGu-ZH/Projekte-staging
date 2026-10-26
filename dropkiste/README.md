# Dropkiste

Eine eigenständige Dateiübergabe-App für **dropkiste.app**, PHP 8.3–8.5 und SQLite.
Keine Verbindung zur vorherigen Gesundheitsapp, keine externen JavaScript-Dienste,
kein Node-Build und keine MySQL-Datenbank notwendig.

## Was Version 1 kann

- Passwortgeschützte Verwaltung zum Hochladen einer Datei pro Übergabe.
- Mehrere eingeladene Personen, jede mit einem eigenen zufälligen 256-Bit-Link.
- E-Mail-Einladung: Abgleich der eingegebenen E-Mail-Adresse.
- SMS-Einladung: Abgleich der Telefonnummer mit internationaler Vorwahl.
- Links kopieren oder im eigenen E-Mail-/SMS-Programm öffnen.
- Kein automatischer Versand und keine laufenden SMS-/Mail-Anbietergebühren.
- Downloadstatus je Person; mehrere Downloads bleiben möglich, solange die Datei verfügbar ist.
- Datei nach dem vollständigen Versand an alle Eingeladenen löschen; ansonsten nach spätestens 30 Tagen.
- Übergaben vorzeitig beenden; abgebrochene Übertragungen zählen nicht als erfolgreich, soweit der Server den Abbruch erkennt.
- Responsive Oberfläche für Computer und Smartphone.

Das ist eine App für zeitlich begrenzte Dateiübergaben: kein permanentes Dropbox-Laufwerk,
keine Synchronisierung, keine Benutzerkonten pro Absender und keine Ordnerverwaltung.
Die erste Version hat ein Verwaltungspasswort für den Eigentümer.

**Identitätsabgleich:** Adresse oder Telefonnummer plus persönlicher Link werden geprüft.
Es wird kein zusätzlicher Einmalcode versendet. Der Abgleich beweist deshalb nicht den Besitz
eines Postfachs oder Telefons. Links nicht öffentlich weitergeben.

**Downloadsemantik:** Erfolgreich heißt, dass PHP alle Dateibytes übertragen und keinen
Verbindungsabbruch erkannt hat. Browser/Netzwerk können puffern. Ob jemand tatsächlich
„Speichern“ bestätigt oder die Datei aufbewahrt, lässt sich serverseitig nicht feststellen.

**Ablauf:** Ab dem Ablaufzeitpunkt wird der Zugriff gesperrt. Der Timer entfernt abgelaufene
Dateien standardmäßig innerhalb einer weiteren Minute. Laufende Streams werden beim nächsten
Übertragungsschritt beendet und ihre Datei danach freigegeben. Das Entfernen per `unlink`
ist keine garantierte physische Überschreibung von SSD-Blöcken. Der Code legt keine Dateibackups an.

## Installation auf dem bestehenden IONOS-Server

Voraussetzungen: Root-Zugang, Apache und PHP sind bereits installiert, DNS zeigt auf den VPS,
und das funktionierende Let's-Encrypt-Zertifikat für `dropkiste.app` ist vorhanden.
Die Installation tauscht die Apache-Site für diese Domain aus. Die bisherigen Dateien unter
`/var/www/dropkiste` und die MariaDB-Datenbank werden dabei weder gelöscht noch übernommen.
Die neue Anwendung hat ihren eigenen, anfangs leeren SQLite-Speicher.

1. `dropkiste-release.tar.gz` auf den eigenen Computer herunterladen.
2. Im Terminal **auf dem eigenen Computer**, im Downloadordner:

   ```bash
   scp dropkiste-release.tar.gz root@85.215.224.124:/root/
   ```

3. In der SSH-Sitzung **auf dem Server**:

   ```bash
   mkdir -p /root/dropkiste-install
   tar -xzf /root/dropkiste-release.tar.gz -C /root/dropkiste-install
   cd /root/dropkiste-install/dropkiste
   bash bin/install-ubuntu.sh
   ```

4. Ein neues Verwaltungspasswort wählen (mindestens 14 Zeichen); Eingabe wird nicht angezeigt.
   Das Installationsskript installiert `php-sqlite3`, richtet Dateirechte, Apache und den
   Bereinigungs-Timer ein. Es verwendet das bestehende Zertifikat weiter.
5. Prüfen:

   ```bash
   curl --noproxy '*' --resolve dropkiste.app:443:127.0.0.1 \
     --fail --show-error 'https://dropkiste.app/api.php?action=session'
   systemctl status dropkiste-cleanup.timer --no-pager
   certbot renew --dry-run
   ```

6. `https://dropkiste.app` neu laden, anmelden, harmlose Testdatei hochladen.
   Zwei eigene Testempfänger eintragen, beide Links getrennt öffnen, falsche Identität testen,
   anschließend beide korrekt herunterladen. In der Verwaltung muss danach „Beendet“ und 2/2 stehen.
   Smartphone ebenfalls testen. Erst nach diesen Tests echte Dateien verwenden.

Das Installationsskript wurde hier auf Syntax geprüft; die Ausführung gegen den echten
IONOS-Server und dessen Apache/Systemd muss in deiner SSH-Sitzung erfolgen.

### Pfade und Konfiguration

| Pfad | Inhalt |
|---|---|
| `/etc/dropkiste/config.php` | Einstellungen und Passwort-Hash; root:www-data, 640 |
| `/var/lib/dropkiste` | SQLite-Datei und Uploads; nur www-data zugänglich |
| `/opt/dropkiste-app/releases/<Zeitstempel>` | Programmdateien, Eigentümer root |
| `…/public` | Einziges öffentliches Verzeichnis |
| `/root/dropkiste-backup-<Zeitstempel>` | Vorherige Apache-Konfiguration |

Einstellbare Werte stehen zentral in `config.example.php`; der Installer übernimmt diese
nach `/etc/dropkiste/config.php`. Standard: **100 MiB pro Datei, 2 GiB Gesamtspeicher,
20 Empfänger, höchstens 30 Tage**. Auch Sitzungslänge, Fehlversuche und parallele Downloads
sind konfigurierbar. Eine Einstellung über 30 Tage wird immer auf 30 begrenzt.

Bei einer späteren Änderung von `max_file_bytes` ebenfalls PHP `upload_max_filesize`,
`post_max_size` in `/etc/php/<Version>/apache2/conf.d/99-dropkiste.ini` sowie Apache
`LimitRequestBody` anpassen; das POST-Limit muss etwas größer als die Datei sein.
Danach `apache2ctl configtest && systemctl restart apache2`.
Der Installer leitet diese Limits bei der Erstinstallation automatisch ab.
Bei Änderung des Bereinigungsintervalls auch `OnUnitActiveSec` im Timer anpassen und
`systemctl daemon-reload && systemctl restart dropkiste-cleanup.timer` ausführen.

Passwort ändern: auf dem Server einen neuen Hash mit `password_hash()` aus einer verdeckten
Eingabe erzeugen und `admin_password_hash` ersetzen. Keine Passwörter in Chat, Shell-History
oder Prozessargumenten hinterlegen. Bestehende Sitzungen laufen bis zur konfigurierten Frist weiter.

### Rückkehr zur bisherigen Website

Apache-Dateien `dropkiste.conf` und `dropkiste-le-ssl.conf` aus dem vom Installer gemeldeten
Sicherungsordner zurück nach `/etc/apache2/sites-available/` kopieren, dann
`apache2ctl configtest && systemctl reload apache2`. Bei dauerhafter Rückkehr
`systemctl disable --now dropkiste-cleanup.timer` ausführen. Vorhandene Dropkiste-Dateien und
Einstellungen zunächst erhalten; nicht ungeprüft löschen.

### Updates

Erstinstallation nicht erneut ausführen: das Skript bricht bei vorhandener Konfiguration ab.
Eine neue Version in ein neues Release-Verzeichnis legen, die bestehende Konfiguration einbinden,
PHP-Dateien prüfen, Apache-DocumentRoot und Cleanup-Service auf dasselbe neue Release umstellen,
`apache2ctl configtest`, `systemctl daemon-reload` und Apache neu starten. Vorher Daten und
Konfiguration konsistent sichern. Datei-Backups dürfen die zugesagte Löschfrist nicht verlängern.
Diese erste Version enthält noch keinen automatischen Update-/Migrationsdienst.

## Lokale Entwicklung und Tests

Code außerhalb des ursprünglichen Gesundheitsapp-Repositories. Keine PHP-Paketabhängigkeiten.
PHP benötigt `pdo_sqlite`. Für einen lokalen HTTP-Test eine private Konfiguration mit
`base_url=http://127.0.0.1:8090`, isoliertem `storage_dir`, `max_file_bytes=1048576`,
`max_storage_bytes=2097152`, `attempts_per_window=100` und einem Hash von
`local-test-password` unter `tests/runtime/config.php` anlegen (Beispielwerte ansonsten übernehmen).
Die Testkonfiguration kann mit `php tests/setup.php` erzeugt werden.
Nur dieses Testpasswort verwenden; niemals die Produktivkonfiguration für Tests nutzen.

```bash
DROPKISTE_CONFIG="$PWD/tests/runtime/config.php" \
  php -d upload_max_filesize=2M -d post_max_size=3M -S 127.0.0.1:8090 -t public
```

In einem zweiten Terminal:

```bash
python3 tests/integration.py
node tests/browser.js
```

Der Integrationstest setzt ausschließlich Daten im isolierten `tests/runtime/storage` zurück.
Browser-Test benötigt Playwright und Chromium (`CHROMIUM_PATH`, Standard `/usr/bin/chromium`).
Er prüft Desktop-Upload, zwei persönliche Links, falsche Identität, Desktop-/Mobile-Downloads,
Abschlussstatus, Mobilansicht und JavaScript-Fehler. Browser-Downloads sind synthetische Testdaten.
Der PHP-Entwicklungsserver ist ausschließlich für lokale Tests geeignet.

## Sicherheitsgrenzen

Dateien liegen außerhalb des Webroots und werden als Download mit `nosniff` ausgeliefert.
Einladungslinks stehen im URL-Fragment; Tokens werden in SQLite nur gehasht gespeichert
und nicht in normalen Access-Log-URLs übertragen. Identitätsversuche und Admin-Logins
werden pro IP begrenzt. SameSite-/HttpOnly-Cookies, CSRF-Schutz und CSP sind vorgesehen.
Die IONOS-Installation benötigt HTTPS; keine unverschlüsselten Einladungen versenden.
Kein Virenscanner und keine Ende-zu-Ende-Verschlüsselung enthalten; der Serverbetreiber
kann auf Dateien zugreifen. Ein automatischer Mail-/SMS-Versand mit Einmalcodes ist eine
separate Erweiterung mit sicher zu hinterlegenden Anbieterzugängen.
