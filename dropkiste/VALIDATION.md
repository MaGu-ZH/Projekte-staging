# Version 2 – Validierung am 26.10.2026

- PHP 8.5: 39 bestehende API-/Integrationsprüfungen erfolgreich.
- 39 zusätzliche Mehrbenutzerprüfungen: Anlegen, Rollen, schwache/duplizierte Zugänge,
  eigene Übergaben, verweigerte Fremdzugriffe, Sperren, erneutes Aktivieren, Passwortreset,
  Entzug alter Sitzungen, eigener Passwortwechsel und manueller Fallback bei Versandfehlern.
- Browser: bisheriger Desktop-/Mobil-Upload und Downloadablauf weiterhin erfolgreich.
- Zusätzlicher Browser-Test: Benutzer anlegen, mobile Benutzeranmeldung, rollenspezifische
  Ansicht, Kontosperre für aktive Sitzung, Passwortreset, Aktivierung und eigener Passwortwechsel.
- Migration einer v1-Datenbank und alten Konfiguration zweimal erfolgreich: aktive Datei und
  Einladungstoken unverändert, ursprüngliche Übergabe dem Betreiber zugeordnet.
- SMTP-Fixture: TLS mit Authentifizierung, STARTTLS, gültiger persönlicher Link im Mailtext,
  Ablehnung eines nicht vertrauenswürdigen Zertifikats sowie einer SMTP-Empfängerablehnung.
- PHP-, JavaScript- und Shell-Syntaxprüfungen erfolgreich.

Nicht geprüft: echte Zustellung über ALL-INKL ohne Postfachdaten, echte SMS über Twilio ohne
Account sowie Update-Ausführung auf IONOS. SMTP-Tests versenden ausschließlich an einen lokalen
Testserver. Kein Smartphone-Hardwaretest; Browser-Tests verwenden ein mobiles Chromium-Profil.
