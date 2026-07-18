# Produktionsbetrieb: Readiness, Backups und Aufbewahrung

## Technisches Readiness-Gate

```bash
docker compose -f compose.production.yaml exec -T php-fpm \
  php artisan erin:ops:readiness --strict --probe --json
```

Oder als gebündelter Lauf:

```bash
bash scripts/ops/release-gate.sh
```

Die Prüfung validiert sicherheitsrelevante Produktionskonfiguration, strukturierte Logs, Redis, privaten S3-kompatiblen Storage, ClamAV sowie referenzierte Backup-, Security-, DPO-, Legal- und Pilot-Evidenz. Eine gesetzte Referenz ist nur ein Verweis auf die externe Freigabe und nicht die Freigabe selbst.

Das gebündelte Script führt zusätzlich `erin:stripe:staging-check --remote` und `erin:zammad:smoke` aus. Beide Integrationsprüfungen sind read-only: Stripe muss aktive Test-Prices für alle Launchpakete liefern und Zammad muss eine sichere HTTPS-URL sowie ein gültiges Agent-Token bestätigen.

## Observability

Produktionslogs gehen als JSON an `stderr`. Mindestens folgende Signale müssen im eingesetzten Monitoring eigene Alarme erhalten:

| Signal | Warnung | Kritisch |
|---|---|---|
| `ops.queue_health` | Queue nähert sich dem Grenzwert | Status `backpressure` oder `unavailable` |
| fehlgeschlagene Jobs | erster Fehler | anhaltender oder wachsender Bestand |
| Stripe-/Zammad-Webhooks | wiederholter Retry | dauerhaft fehlgeschlagen |
| ClamAV/privater Storage | erhöhte Latenz | aktive Readiness-Probe schlägt fehl |
| HTTP | erhöhte 5xx-Rate/Latenz | Readiness oder Kernfluss nicht verfügbar |
| Datenbank/Redis | Ressourcen steigen | Verbindung, Replikation oder Persistenz gestört |
| Backup | Lauf verspätet | Backup oder Prüfsumme fehlt |

Grenzwerte werden nicht blind aus diesem Dokument übernommen. Sie sind mit Pilotdaten zu kalibrieren und anschließend in der Monitoringplattform zu versionieren.

## Datenbank-Backup und Restore-Drill

Ein lokaler, nur für den anschließenden Transfer vorgesehener Dump:

```bash
bash scripts/ops/database-backup.sh /sicherer/temporärer/pfad
```

Der Dump erhält Dateirechte über `umask 077` und eine SHA-256-Prüfsumme. Er enthält personenbezogene Daten und muss unmittelbar in ein verschlüsseltes, zugriffsbeschränktes Backupziel übertragen und anschließend vom temporären Host gelöscht werden.

Ein Restore-Drill nutzt ausschließlich eine neu erzeugte temporäre Datenbank und löscht sie anschließend:

```bash
ERIN_RESTORE_DRILL_CONFIRM=RESTORE_IN_TEMP_DATABASE \
  bash scripts/ops/database-restore-drill.sh \
  /sicherer/temporärer/pfad/erin-YYYYMMDDTHHMMSSZ.sql
```

Der erfolgreiche Lauf prüft Prüfsumme, Import und Migrationstabelle. Fachliche Stichproben, Entschlüsselung, Berechtigungen, Wiederanlauf der Anwendung sowie die tatsächlich erreichten RPO-/RTO-Ziele müssen im Drillprotokoll ergänzt werden.

## Backup-Matrix

- **MySQL:** verschlüsselter Dump, getrenntes Konto/Projekt, unveränderbare Versionen und regelmäßiger Restore-Drill.
- **MinIO/S3:** Versionierung und Replikation in ein getrenntes Konto bzw. eine getrennte Region; Schlüssel nicht mit dem Primärsystem teilen. Stichproben müssen Dokument-Metadaten und Objektinhalt gemeinsam wiederherstellen.
- **Redis:** keine alleinige Datenquelle. Warteschlangen müssen idempotent sein; persistente Geschäftsdaten liegen in MySQL.
- **Meilisearch:** aus MySQL rekonstruierbar. Suchindizes enthalten keine Identitätsdaten und werden nicht als maßgebliches Backup behandelt.
- **Anwendung:** unveränderliches Image, versionierte Migrationen und separat gesicherte Secret-Referenzen. Secrets gehören nicht in Dumps oder das Repository.

RPO, RTO, Backupfrequenz, Aufbewahrung und geografische Ablage bleiben bis zur dokumentierten Risiko- und Datenschutzfreigabe offen.

## Aufbewahrung und Löschung

Alle Regeln sind standardmäßig deaktiviert. Nach der Freigabe werden die Tage über folgende Variablen gesetzt:

- `ERIN_RETENTION_LOGIN_HISTORY_DAYS`
- `ERIN_RETENTION_READ_NOTIFICATION_DAYS`
- `ERIN_RETENTION_ACTIVITY_DAYS`
- `ERIN_RETENTION_CANDIDATE_IMPORT_DAYS`
- `ERIN_RETENTION_FAILED_JOB_DAYS`

Dry-Run:

```bash
docker compose exec -T laravel php artisan erin:ops:prune --json
```

Ausführung:

```bash
docker compose exec -T laravel php artisan erin:ops:prune --execute --json
```

Der Scheduler führt die Ausführung täglich aus; bei Frist `0` wird nichts gelöscht. Das Log-Event heißt `ops.retention_prune`.

Bewusst ausgeschlossen sind Audit-Logs, Webhook-Idempotenzbelege, Supportkonversationen, Bewerbungen, Verträge, Abrechnungsdaten und fachliche Dokumente. Für diese Daten braucht es eigene, rechtlich geprüfte Lösch-, Sperr- oder Pseudonymisierungsabläufe, bevor eine automatische Löschung aktiviert werden darf.
