# Observability und Incident-Runbooks

## Signale und Alarmierung

Alle HTTP-Antworten tragen `X-Request-ID`; dieselbe UUID liegt im strukturierten
JSON-Log als `correlation_id`. Queue-Jobs, Webhooks und externe Providerbelege
verwenden zusätzlich ihre fachlichen, idempotenten IDs. Passwörter, Tokens,
Dokumentinhalte, Chattexte und KI-Eingaben sind in Telemetrie verboten.

Die Readiness-Probe `/health/ready` prüft Datenbank, Redis, privaten Storage,
Meilisearch, ClamAV und den Scheduler-Heartbeat. Produktion liefert nur
Gesamtstatus und Zeitpunkt; Komponentenwerte sind ausschließlich lokal und in
Tests sichtbar.

`GET /health/metrics` liefert ausschließlich mit
`Authorization: Bearer <ERIN_METRICS_TOKEN>` Prometheus-kompatible Metriken.
Ein fehlender oder falscher Token antwortet bewusst mit 404. Queue-Start,
-Abschluss und -Fehler werden mit Connection, Queue und Job-UUID strukturiert
protokolliert; weder Job-Logs noch Metriken enthalten Payloads,
Dokumentinhalte, Tokens oder KI-Eingaben.

| Signal | Warnung | Kritisch | Erstmaßnahme |
|---|---:|---:|---|
| HTTP 5xx | > 1 % / 5 min | > 5 % / 5 min | Release/Abhängigkeiten prüfen |
| p95-Latenz | > 750 ms | > 2 s | langsame Route/DB prüfen |
| Queue pending | > 250 | > 500 | Worker und fehlerhafte Jobs prüfen |
| Failed Jobs | > 0 | wachsend über 5 min | Payload ohne Inhalt, Exception prüfen |
| Webhook-Lag | > 2 min | > 10 min | Providerstatus und Inbox prüfen |
| Backupalter | > 7 h | > 12 h | Backup-Runner/Repository prüfen |
| Scheduler | > 2 min | > 5 min | Scheduler-Container prüfen |

Warnungen müssen mindestens fünf Minuten anliegen, bevor sie eskalieren;
Recovery-Benachrichtigungen schließen den Alarm. Eine Integration erzeugt
höchstens einen aktiven Alarm je Ursache.

## Abhängigkeiten

- **MySQL:** Schreibpfade stoppen, Verbindungs-/Speicherstatus prüfen, keine
  Reparatur auf der einzigen Kopie. Bei Datenverlust Restore-Runbook starten.
- **Redis:** Queue und Sessions betroffen; keine Worker vervielfachen, bevor
  Persistenz und Speicherlimit geklärt sind.
- **MinIO/S3:** Uploads sperren, niemals auf öffentlichen Ersatzbucket
  umstellen. Objekt- und DB-Konsistenz gemeinsam prüfen.
- **Meilisearch:** Suche degradieren; Index aus MySQL neu aufbauen. Der Index
  ist nie Primärquelle.
- **ClamAV:** Uploads bleiben in Quarantäne. Scans nicht umgehen.
- **Reverb:** HTTP bleibt verfügbar; Clients reconnecten lassen und
  Origin-/Rate-Limit-Fehler prüfen.
- **Stripe:** Webhooks weiter idempotent annehmen; Entitlements nicht manuell
  ohne bestätigten Stripe-Zustand freischalten.
- **OpenAI:** KI-Funktionen per Kill-Switch deaktivieren; Recruitingstatus
  bleiben vollständig menschlich gesteuert.
- **LiveKit:** Interviews auf Terminverschiebung/externen Notfallweg umstellen;
  keine unverschlüsselte Ersatzverbindung anbieten.
- **Zammad:** Outbox bleibt erhalten; Synchronisation nach Recovery
  idempotent fortsetzen.

## Game Day und Postmortem

Quartalsweise werden Queue-Stau, Storage-Ausfall und verspäteter Webhook in
Staging kontrolliert simuliert. Nachgewiesen werden Alarm, Korrelation,
Eskalation, Recovery und eine Negativkontrolle gegen personenbezogene
Telemetry. Für Severity 1/2 folgt innerhalb von fünf Arbeitstagen ein
blameless Postmortem mit Timeline, Ursache, Wirkung, Erkennungslücke,
Korrekturmaßnahmen, Owner und Fälligkeit.
