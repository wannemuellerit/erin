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

Das gebündelte Script führt zusätzlich
`erin:ops:security-audit --json`, `erin:stripe:staging-check --remote` und
`erin:zammad:smoke` aus. Die technische Security-Baseline prüft unter anderem
Sessions, Admin-2FA, signierte Downloads, Datenkontrollen und
Realtime-Missbrauchsschutz. Beide Integrationsprüfungen sind read-only: Stripe
muss aktive Test-Prices für alle Launchpakete liefern und Zammad muss eine
sichere HTTPS-URL sowie ein gültiges Agent-Token bestätigen.

Die strukturierten Evidenzfelder, formalen Rollentrennungen und externen
Checklisten sind in [launch-gates.md](launch-gates.md) beschrieben.

## Unveränderlicher Release und Build-SHA

Jeder Produktionsbuild verwendet den vollständigen Git-Commit gleichzeitig als
Build-Argument und Image-Tag:

```bash
export ERIN_BUILD_SHA="$(git rev-parse HEAD)"
export ERIN_APP_TAG="$ERIN_BUILD_SHA"
export ERIN_GOVERNANCE_TRUST_ROOT_SHA256="$(
  sha256sum "$ERIN_GOVERNANCE_TRUST_ROOT_FILE" | awk '{print $1}'
)"
docker compose -f compose.production.yaml build
```

Der Docker-Build lehnt fehlende oder verkürzte SHAs ab, schreibt den Commit
root-owned nach `/app/.erin-build-sha` und setzt das OCI-Label
`org.opencontainers.image.revision`. Der EntryPoint startet nicht, wenn
eingebauter SHA, `ERIN_BUILD_SHA` und `ERIN_APP_TAG` voneinander abweichen.
`latest`, `main`, `stable` oder andere bewegliche Tags erfüllen das Gate nicht.

Der Build verlangt außerdem den 64-stelligen SHA-256 des separat verwalteten
Governance-Trust-Roots und schreibt ihn read-only nach
`/app/.erin-governance-trust-root-sha256`. Ein später gemounteter Root mit
abweichenden Bytes wird abgewiesen; die Laufzeitkonfiguration kann diesen Pin
nicht ersetzen.

Die Freigabeevidenz wird gegen den eingebauten Wert geprüft, nicht nur gegen
eine zur Laufzeit überschreibbare Umgebungsvariable. Damit bezieht sich der
Security-Review nachweislich auf den tatsächlich gestarteten Code.

## Proxy-Vertrauensgrenze

`TRUSTED_PROXIES` darf niemals `*`, `**`, `0.0.0.0/0` oder `::/0` enthalten.
Im Compose-Stack erhält das interne Netzwerk ein explizites Subnetz, zum
Beispiel:

```dotenv
ERIN_INTERNAL_SUBNET=172.30.0.0/24
TRUSTED_PROXIES=172.30.0.0/24
```

Mindestens das interne Subnetz muss in `TRUSTED_PROXIES` stehen. Weitere
Einträge sind nur als konkrete IPs/CIDRs zulässig und müssen der realen
Topologie entsprechen.

Nginx verwirft den standardisierten `Forwarded`-Header und überschreibt
`X-Forwarded-For`, `X-Forwarded-Host`, `X-Forwarded-Port` und
`X-Forwarded-Proto`, bevor PHP-FPM oder Reverb erreicht werden. Dadurch kann ein
Client seine IP oder das HTTPS-Schema nicht über mitgebrachte Header fälschen.
Der veröffentlichte HTTP-Port darf ausschließlich hinter dem freigegebenen
TLS-Terminator bzw. innerhalb des geschützten Ingress liegen.

## Bucket-begrenzter MinIO-App-Nutzer

Root-Zugangsdaten werden nur dem MinIO-Server und dem einmaligen
`minio-init`-Container bereitgestellt. PHP-FPM, Queue, Scheduler, Reverb und
Migrationen erhalten ausschließlich:

```dotenv
MINIO_APP_USER=erin-app
MINIO_APP_PASSWORD=<eigenes Deployment-Secret>
```

`minio-init` verweigert identische Root-/App-Zugangsdaten, legt eine Policy für
exakt `AWS_BUCKET` an und erlaubt nur benötigte Bucket-/Objektaktionen. Anonyme
Bucketzugriffe bleiben deaktiviert. Änderungen an Bucketnamen oder
Zugangsdaten erfordern anschließend einen aktiven Storage-Smoke-Test mit dem
App-Nutzer.

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

Die vollständige, verständliche Erklärung von RPO, RTO, Verschlüsselung,
MySQL-/MinIO-Konsistenz und Pflicht-Evidenz steht in
[backup-restore-drill.md](backup-restore-drill.md).

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

Der einfache Datenbanklauf prüft Prüfsumme, Import und Migrationstabelle. Der
vollständige lokale Drill in
`scripts/ops/local-encrypted-restore-drill.sh` prüft zusätzlich verschlüsselte
MySQL-/MinIO-Artefakte, kanonische Inhalte und Struktur, vollständige
DB-zu-Objekt-Referenzen, Negativkontrollen, Quell-Quiesce, Wiederanlauf sowie
RPO/RTO. Er bleibt synthetische lokale Evidenz und ersetzt weder einen
Produktions-Restore noch unabhängige Prüfung.

## Backup-Matrix

- **MySQL:** verschlüsselter Dump, getrenntes Konto/Projekt, unveränderbare Versionen und regelmäßiger Restore-Drill.
- **MinIO/S3:** Versionierung und Replikation in ein getrenntes Konto bzw. eine getrennte Region; Schlüssel nicht mit dem Primärsystem teilen. Stichproben müssen Dokument-Metadaten und Objektinhalt gemeinsam wiederherstellen.
- **Redis:** keine alleinige Datenquelle. Warteschlangen müssen idempotent sein; persistente Geschäftsdaten liegen in MySQL.
- **Meilisearch:** aus MySQL rekonstruierbar. Suchindizes enthalten keine Identitätsdaten und werden nicht als maßgebliches Backup behandelt.
- **Anwendung:** unveränderliches Image, versionierte Migrationen und separat gesicherte Secret-Referenzen. Secrets gehören nicht in Dumps oder das Repository.

RPO, RTO, Backupfrequenz, Aufbewahrung und geografische Ablage werden vor dem
Pilot anhand der dokumentierten Risiko- und Datenschutzentscheidung
festgelegt. Ziel- und Messwerte für MySQL und MinIO/S3 müssen danach im
strukturierten Restore-Gate hinterlegt sein; ein Ziel darf nicht erst nach der
Messung passend gewählt werden.

Der private S3-Datenträger verwendet `PRIVATE_FILESYSTEM_PREFIX` ausschließlich
als relativen Bucket-Präfix. Ein lokaler absoluter Pfad darf dort nicht stehen,
weil er sonst Bestandteil jedes S3-Objektschlüssels würde. Nur beim
`PRIVATE_FILESYSTEM_DRIVER=local` setzt Erin automatisch
`storage/app/private` als lokales Root-Verzeichnis.

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
