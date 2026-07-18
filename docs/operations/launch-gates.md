# Security-, Datenschutz-, Legal- und Pilot-Gates

Erin darf erst öffentlich freigegeben werden, wenn jeder Gate-Verantwortliche die zugehörige Evidenz geprüft hat. Die Anwendung speichert lediglich Referenzen auf diese Nachweise.

## Technische Sicherheitsprüfung

- Mandanten- und Rollenmatrix inklusive Support-Impersonation erneut testen.
- 2FA-Erzwingung für Superadmin und Support verifizieren.
- Abhängigkeiten, Containerimages und Secrets scannen.
- Uploads, signierte Downloads, ClamAV, Dateitypen und maximale Größen prüfen.
- Stripe-, Zammad- und LiveKit-Webhooks auf Signatur, Replay und Idempotenz testen.
- Rate Limits, Brute-Force-Schutz, Browser-Header, TLS und Proxygrenzen prüfen.
- Externen Penetrationstest und nachvollziehbare Behebung kritischer Befunde dokumentieren.

Referenz: `ERIN_SECURITY_REVIEW_REFERENCE`

## Datenschutz/DPO

- Verzeichnis der Verarbeitungstätigkeiten, Rechtsgrundlagen und Einwilligungen freigeben.
- Datenminimierung, Zweckbindung, Rollen, Empfänger und Drittlandtransfers prüfen.
- Lösch- und Aufbewahrungsplan pro Datenklasse freigeben.
- DSGVO-Export, Berichtigung, Einschränkung, Widerspruch und Löschung als vollständigen Drill durchführen.
- Auftragsverarbeitungsverträge und technische/organisatorische Maßnahmen prüfen.
- Datenschutz-Folgenabschätzung für Recruiting-, Matching- und KI-Funktionen abschließen.

Referenz: `ERIN_DPO_APPROVAL_REFERENCE`

## Rechtliche Freigabe

- AGB, Datenschutzinformationen, Impressum, Widerruf/Kündigung und Paketlaufzeiten freigeben.
- AGG-konforme Suche, Matchingbegründungen und menschliche Entscheidungen prüfen.
- Visa-, Relocation-, Referral-, Bewertungs- und Provisionsaussagen freigeben.
- BARMER-/Partneraussagen, Marken und Logos nur mit schriftlicher Erlaubnis verwenden.
- AI-Act-Rollen, Risikomanagement, Transparenz, Aufsicht und Protokollierung festlegen.

Referenz: `ERIN_LEGAL_APPROVAL_REFERENCE`

## Backup-/Restore-Gate

- Verschlüsselte Datenbank- und Objekt-Backups in einem getrennten Sicherheitsbereich nachweisen.
- Wiederherstellung in isolierter Umgebung technisch und fachlich prüfen.
- RPO/RTO messen, Abweichungen dokumentieren und Verantwortliche benennen.
- Zugriff, Schlüsselrotation, Unveränderbarkeit und Löschung alter Backups testen.

Referenz: `ERIN_BACKUP_RESTORE_VERIFIED_AT`

## Begleiteter Pilot

- Verantwortliche Person und Stellvertretung benennen.
- Kleine, ausdrücklich freigegebene Firmen- und Kandidatengruppe auswählen.
- Support-, Incident- und Eskalationskanäle besetzen.
- Erfolgs-, Qualitäts-, Fairness- und Sicherheitskennzahlen vorab festlegen.
- Tägliche Prüfung in der Startphase sowie Stop-/Rollback-Kriterien vereinbaren.
- Erst nach dokumentierter Pilotentscheidung schrittweise weitere Nutzer zulassen.

Verantwortung: `ERIN_PILOT_OWNER`

## Maschinenlesbare Prüfung

```bash
php artisan erin:ops:readiness --strict --probe --json
```

Exit-Code `0` bedeutet nur, dass technische Prüfungen erfolgreich waren und für alle externen Gates eine Referenz gesetzt ist. Der Inhalt der Nachweise muss weiterhin von den jeweils verantwortlichen Personen geprüft werden.
