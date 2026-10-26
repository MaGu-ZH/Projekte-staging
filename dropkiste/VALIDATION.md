# Validierung am 26.10.2026

- PHP 8.5.12: 39 API-/Integrationsprüfungen erfolgreich (tests/integration.py).
- Zusätzlich vorheriger Kernlauf unter PHP 8.3 erfolgreich.
- Chromium/Playwright: Desktop-Upload, zwei individuelle Einladungen, falsche Identität,
  Desktop- und Smartphone-Download mit Vergleich des Dateiinhalts, Anzeige 2/2 und beendet,
  mobile Upload-Verwaltung ohne horizontalen Überlauf, keine JavaScript-Laufzeitfehler.
- Abgewiesene Uploads: nicht angemeldet, leer, zu groß, doppelte Empfänger,
  ungültige Telefonnummer, ungültige Aufbewahrungsdauer, volle Speicherquote.
- Gelöscht nach letztem Download, bei Ablauf und durch manuelles Beenden;
  Empfängerdaten nach Löschung entfernt. Dateiexistenz auf dem Datenträger geprüft.
- PHP-Syntaxprüfung, JavaScript-Syntaxprüfung und Bash-Syntaxprüfung erfolgreich.
- Cleanup-CLI erfolgreich ausgeführt.

Nicht auf dem IONOS-Produktivserver ausgeführt: Installation, Apache-Umschaltung,
Systemd-Timer und abschließende HTTPS-Funktionstests. Diese erfolgen nach Übertragung
in der SSH-Sitzung des Eigentümers. Smartphone wurde im Chromium-Geräteprofil emuliert;
kein physisches iPhone-/Android-Gerät getestet. Kein automatischer E-Mail-/SMS-Versand.
