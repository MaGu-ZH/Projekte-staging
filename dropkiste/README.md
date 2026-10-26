# Dropkiste

Eine eigenständige Dateiübergabe-App für **dropkiste.app**, PHP 8.3–8.5 und SQLite.
Keine Verbindung zur vorherigen Gesundheitsapp, keine externen JavaScript-Dienste,
kein Node-Build und keine MySQL-Datenbank notwendig.

## Was Version 2 kann

- Benutzerverwaltung durch Administratoren: Konten anlegen, sperren, aktivieren und Passwörter zurücksetzen.
- Benutzer sehen nur ihre eigenen Übergaben; andere Benutzer können sie weder auflisten noch beenden.
- Kontosperren und Passwortänderungen entziehen bestehenden Sitzungen sofort den Zugriff.
- Passwortgeschützte Verwaltung zum Hochladen einer Datei pro Übergabe.
- Mehrere eingeladene Personen, jede mit einem eigenen zufälligen 256-Bit-Link.
- E-Mail-Einladung: Abgleich der eingegebenen E-Mail-Adresse.
- SMS-Einladung: Abgleich der Telefonnummer mit internationaler Vorwahl.
- Links kopieren oder im eigenen E-Mail-/SMS-Programm öffnen.
- Optional direkter E-Mail-Versand über SMTP (TLS/STARTTLS), auch mit einem passenden ALL-INKL-Postfach.
- Optionaler SMS-Adapter für Twilio; standardmäßig deaktiviert. SMS verursacht je nach Anbieter Gebühren.
- Ohne konfigurierte Anbieter bleiben die Links manuell versendbar; fehlgeschlagener Versand wird angezeigt.
- Downloadstatus je Person; mehrere Downloads bleiben möglich, solange die Datei verfügbar ist.
- Datei nach dem vollständigen Versand an alle Eingeladenen löschen; ansonsten nach spätestens 30 Tagen.
- Übergaben vorzeitig beenden; abgebrochene Übertragungen zählen nicht als erfolgreich, soweit der Server den Abbruch erkennt.
- Responsive Oberfläche für Computer und Smartphone.

Das ist eine App für zeitlich begrenzte Dateiübergaben: kein permanentes Dropbox-Laufwerk,
keine Synchronisierung und keine Ordnerverwaltung.
Konten werden durch Administratoren angelegt, nicht über eine öffentliche Registrierung.
Der ursprüngliche Betreiber meldet sich als **admin** mit seinem bisherigen Verwaltungspasswort an.
Andere Benutzer melden sich mit E-Mail-Adresse und eigenem Passwort an.
Beim Sperren eines Kontos bleiben bereits ausgegebene Empfängerlinks bis zum regulären
Ablauf oder Abschluss verfügbar; die Sperre beendet den Benutzerzugang, nicht die Übergaben.
Der Betreiber behält seine bisherigen Übergaben. Auch Administratoren sehen in der Übergabenliste
nur eigene Übergaben; sie können allerdings Benutzerkonten verwalten und Passwörter zurücksetzen.

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

Betreiberpasswort ändern: auf dem Server einen neuen Hash mit `password_hash()` aus einer verdeckten
Eingabe erzeugen und `admin_password_hash` ersetzen. Keine Passwörter in Chat, Shell-History
oder Prozessargumenten hinterlegen. In Version 2 werden bestehende Sitzungen des Betreibers durch den neuen Passwort-Hash ungültig.
Benutzer können ihr eigenes Passwort im angemeldeten Bereich ändern und werden danach abgemeldet.

### Rückkehr zur bisherigen Website

Apache-Dateien `dropkiste.conf` und `dropkiste-le-ssl.conf` aus dem vom Installer gemeldeten
Sicherungsordner zurück nach `/etc/apache2/sites-available/` kopieren, dann
`apache2ctl configtest && systemctl reload apache2`. Bei dauerhafter Rückkehr
`systemctl disable --now dropkiste-cleanup.timer` ausführen. Vorhandene Dropkiste-Dateien und
Einstellungen zunächst erhalten; nicht ungeprüft löschen.

### Bestehende Installation aktualisieren

Auf dem IONOS-Server:

```bash
cd /opt/dropkiste/repo &&
git fetch origin feature/dropkiste-app &&
git checkout --detach FETCH_HEAD &&
bash dropkiste/bin/update-ubuntu.sh
```

Der Updater ist für die mit diesem Projekt installierte Apache-Site gedacht. Er sichert
Konfiguration, Site-Dateien, Cleanup-Service und einen konsistenten SQLite-Stand in einem
neuen Root-Ordner. Upload-Dateien bleiben an ihrem bisherigen Ort. Er installiert PHP-cURL,
legt ein neues Release an, ergänzt die Datenbank und stellt Apache und Cleanup-Service um.
Die Migration ist additiv und wiederholbar. Bestehende Links und Dateien werden erhalten;
alle bisherigen Übergaben bekommen den Besitzer `admin`. Alte Sitzungen erfordern danach
gegebenenfalls eine erneute Anmeldung. Kein Passwort wird dabei geändert.
Ein fehlgeschlagener Apache-Konfigurationstest stellt die vorherigen Site-/Service-Dateien
wieder her. Die Datenbanksicherung ist kein automatischer Komplett-Rollback. Neue Uploads
nach einem Update dürfen bei einer Wiederherstellung nicht versehentlich überschrieben werden.
Die Skriptausführung und abschließenden Funktionstests auf IONOS bleiben ein eigener Prüfschritt.

### E-Mail über ALL-INKL oder einen anderen SMTP-Anbieter

Benötigt werden der **tatsächliche SMTP-Servername** aus den Postfach-Einstellungen,
Port (587 mit STARTTLS oder 465 mit implizitem TLS), Benutzername, Postfachpasswort und
Absenderadresse. Ein Domainname allein genügt nicht immer als SMTP-Servername.
Der konfigurierte SMTP-Anbieter muss Versand von der Absenderadresse erlauben.
SPF/DKIM richten sich nach den Hinweisen des Mailanbieters für die Absenderdomain.

Erst nach dem App-Update auf dem Server:

```bash
cd /opt/dropkiste/repo
bash dropkiste/bin/configure-mail.sh
```

Das Skript fragt die Daten lokal ab; das Passwort wird verdeckt eingegeben und nur in der
privaten Serverkonfiguration gespeichert. Keine Zugangsdaten hier im Chat oder auf GitHub
hinterlegen. Der Aufruf versendet selbst noch keine Nachricht. Anschließend eine harmlose
Datei an die eigene E-Mail-Adresse mit „Direkt über Dropkiste versenden“ schicken und den
Posteingang prüfen. Der Status „Vom Versanddienst angenommen“ bestätigt nur die Annahme
beim SMTP-Anbieter, nicht die Zustellung im Postfach. Bei Fehlern bleiben die persönlichen
Links zum Kopieren erhalten. Direkter Versand ist pro Übergabe auf konfigurierbare fünf
Empfänger beschränkt, damit der synchrone Upload-Aufruf zeitlich begrenzt bleibt.
Bei größeren Verteilerlisten manuell versenden; für Massenversand ist eine Warteschlange nötig.

TLS-Zertifikate werden immer geprüft. Es gibt keine Option, die Prüfung auszuschalten.
Eine eigene Unternehmens-CA kann bei Bedarf als `delivery_ca_file` hinterlegt werden.
Ohne Anbieterangaben bleibt E-Mail deaktiviert. Der echte Versand über dein Postfach muss
nach Konfiguration separat geprüft werden.

### SMS optional aktivieren

Enthalten ist ein deaktivierter Twilio-Adapter. Bevor du SMS aktivierst, brauchst du einen
geeigneten Anbieteraccount mit nutzbarer Absendernummer oder Messaging Service. Die Felder
`account_sid`, `auth_token`, `from` oder `messaging_service_sid` ausschließlich im privaten
`sms`-Abschnitt von `/etc/dropkiste/config.php` ausfüllen; erst dann `enabled` aktivieren.
Die Live-Anbindung ist ohne Account nicht getestet. Keine automatischen Wiederholungsversuche;
fehlgeschlagene Einladungen können manuell weitergegeben werden. Status „angenommen“ ist
keine Zustellbestätigung. Bei einem anderen SMS-Anbieter wird ein eigener Adapter benötigt.

## Lokale Entwicklung und Tests

Code außerhalb des ursprünglichen Gesundheitsapp-Repositories. Keine PHP-Paketabhängigkeiten.
PHP benötigt `pdo_sqlite`. Für einen lokalen HTTP-Test eine private Konfiguration mit
`base_url=http://127.0.0.1:8090`, isoliertem `storage_dir`, `max_file_bytes=1048576`,
`max_storage_bytes=2097152`, `attempts_per_window=100` und einem Hash von
`local-test-password` unter `tests/runtime/config.php` anlegen (Beispielwerte ansonsten übernehmen).
Die Testkonfiguration kann mit `php tests/setup.php` erzeugt werden; der Test-Passwort-Hash
wird dabei einmalig angelegt und bleibt danach stabil.
Nur dieses Testpasswort verwenden; niemals die Produktivkonfiguration für Tests nutzen.

```bash
DROPKISTE_CONFIG="$PWD/tests/runtime/config.php" \
  php -d upload_max_filesize=2M -d post_max_size=3M -S 127.0.0.1:8090 -t public
```

In einem zweiten Terminal:

```bash
python3 tests/integration.py
python3 tests/users.py
node tests/browser.js
node tests/users-browser.js
python3 tests/migration.py
python3 tests/smtp.py
```

Die erweiterten Tests unterstützen `DROPKISTE_TEST_URL` und bei den Container-Fixtures
`DROPKISTE_TEST_CONTAINER`. Migration/SMTP-Fixtures benötigen Docker und OpenSSL.
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
kann auf Dateien zugreifen. Die SMTP-/SMS-Anbindung benötigt sicher zu hinterlegende Anbieterzugänge.
Einmalcodes zur zusätzlichen Empfängerbestätigung sind nicht enthalten.
