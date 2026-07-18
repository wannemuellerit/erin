# Was Schritt 4 bedeutet: verschlüsselter DB-/MinIO-Restore-Drill

Ein Backup ist erst belastbar, wenn nachgewiesen wurde, dass es in einer
isolierten Umgebung vollständig und rechtzeitig wiederhergestellt werden kann.
Das bloße Vorhandensein einer `.sql`-Datei oder eines MinIO-Volumes reicht
nicht.

## RPO und RTO in einfachen Worten

**RPO (Recovery Point Objective)** ist der maximal akzeptierte Datenverlust,
gemessen in Zeit. Ein Ziel von 15 Minuten bedeutet: Nach einem Totalausfall
dürfen höchstens die letzten 15 Minuten an Änderungen fehlen.

**RTO (Recovery Time Objective)** ist die maximal akzeptierte Ausfallzeit. Ein
Ziel von 120 Minuten bedeutet: Spätestens zwei Stunden nach Ausrufung des
Notfalls muss das überprüfte System wieder nutzbar sein.

Für den Drill werden Ziel und tatsächlich erreichter Wert getrennt erfasst:

| System | Ziel-RPO | Erreichtes RPO | Ziel-RTO | Erreichtes RTO |
|---|---:|---:|---:|---:|
| MySQL | vorab festlegen | messen | vorab festlegen | messen |
| MinIO/S3 | vorab festlegen | messen | vorab festlegen | messen |

Das Gate bleibt rot, wenn ein erreichter Wert über seinem Ziel liegt.

## Warum MySQL und MinIO gemeinsam zählen

MySQL enthält unter anderem Dokumentmetadaten, Status und Berechtigungen.
MinIO/S3 enthält die eigentlichen privaten Dateien. Ein wiederhergestellter
Datenbankeintrag ohne zugehöriges Objekt ist unbrauchbar; ein Objekt ohne
Metadaten und Zugriffskontrolle ebenfalls.

Der Drill muss deshalb einen konsistenten Zeitpunkt oder ein dokumentiertes
Konsistenzverfahren verwenden und vollständig in beide Richtungen prüfen:

- jeder private Datenbankpfad verweist auf ein vorhandenes Objekt;
- jedes Anwendungsobjekt besitzt eine gültige Datenbankreferenz;
- ein leerer Bucket ist nur zulässig, wenn auch keine Datenbankreferenz
  existiert;
- abgelehnte, gelöschte oder quarantänisierte Dateien tauchen nicht
  unkontrolliert wieder auf.

## Mindestanforderungen an Verschlüsselung

- Transport zum Backupziel erfolgt TLS-geschützt.
- Daten sind am Backupziel mit einem getrennten Schlüssel verschlüsselt.
- Schlüssel liegen nicht im Repository, Dump oder selben Storage-Konto.
- Zugriff folgt dem Minimalprinzip und wird protokolliert.
- Schlüsselrotation und Wiederherstellung mit dem aktuellen
  Notfallzugriff wurden praktisch getestet.
- Backups sind gegen unbeabsichtigtes Überschreiben und vorzeitiges Löschen
  geschützt.

## Ausführbarer Drill

### Lokaler technischer Drill

Der lokale Drill liest ausschließlich die mit `compose.yaml` gestartete
`local`- oder `testing`-Umgebung. Er verweigert Produktions-Compose-Dateien,
erstellt keine Host-Portfreigaben und startet MySQL sowie MinIO in einem
internen Docker-Netz mit flüchtigen `tmpfs`-Dateisystemen. Vor MySQL-Dump und
MinIO-Mirror aktiviert er den Maintenance-Modus, stoppt Queue und Scheduler
kontrolliert und pausiert den Laravel-Schreibpfad. Ein Exit-Trap stellt diesen
Zustand auch bei einem Fehler wieder her:

```bash
ERIN_RESTORE_DRILL_CONFIRM=LOCAL_ISOLATED_DOCKER_ONLY \
  scripts/ops/local-encrypted-restore-drill.sh
```

Dabei erzeugt der Drill einen zufälligen ephemeren Master-Key und leitet per
HMAC-SHA256 mit getrennten Domain-Labels einen Verschlüsselungs- und einen
MAC-Key ab. MySQL und MinIO werden mit AES-256-CBC und PBKDF2-HMAC-SHA256 mit
mindestens 600.000 Iterationen verschlüsselt. Für jedes Ciphertext-Artefakt
wird anschließend ein HMAC-SHA256 im Encrypt-then-MAC-Verfahren berechnet und
**vor jeder Entschlüsselung** verifiziert. Master-, Verschlüsselungs- und
MAC-Key sowie alle Klartext-Arbeitskopien liegen ausschließlich in einem
gehärteten Docker-`tmpfs`. Nach dem Drill wird das gesamte Volume entfernt.
Das belegt, dass kein Klartext-Backup zurückbleibt; es ist ausdrücklich keine
Behauptung über eine physische Vernichtung auf darunterliegender Hardware.

Der einmalig im `tmpfs` erzeugte deterministische MySQL-Voll-Dump ist zugleich
Quelle des verschlüsselten Artefakts und des kanonischen Datenhashs. Der
Datenhash umfasst alle mit vollständiger Spaltenliste erzeugten
`INSERT INTO`-Zeilen aller Tabellen und sortiert sie byteweise, sodass auch
Tabellen ohne Primärschlüssel stabil verglichen werden. Schema, Routinen,
Events und Trigger werden mit einem separaten, semantisch normalisierten
Strukturhash geprüft.

Die SHA-256-Dateien binden die lokal erzeugte maschinenlesbare Evidenz und ihre
HMAC-Sidecars. Weil die MAC-Schlüssel danach absichtlich nicht aufbewahrt
werden, dienen diese Sidecars bei einer späteren Offline-Prüfung nur der
Konsistenz des lokalen Drillpakets. Sie sind keine externe Signatur und kein
Ersatz für unveränderliche, unabhängig authentifizierte Produktionsevidenz.

Der Drill prüft zusätzlich folgende Negativfälle:

- Entschlüsselung mit einem falschen Schlüssel wird abgewiesen;
- manipuliertes Ciphertext-Artefakt wird durch den HMAC vor der Entschlüsselung
  abgewiesen;
- ein manipulierter HMAC-Sidecar wird abgewiesen;
- ein absichtlich entferntes MinIO-Objekt erzeugt einen Manifestfehler;
- eine Nicht-ID-Änderung und eine gelöschte Datenbankzeile verändern den
  kanonischen Datenhash;
- eine fehlende Datenbankreferenz, ein verwaistes Storage-Objekt und ein
  leerer Bucket mit vorhandener Datenbankreferenz werden abgewiesen.

Vor dem Datenbank-Dump wird außerdem ein eindeutiger Drill-Canary als
Audit-Ereignis geschrieben. Dessen Metadaten enthalten den Pfad eines zweiten,
ebenfalls temporären MinIO-Canarys. Damit beweist jeder erfolgreiche Lauf
positiv mindestens eine echte DB→Objekt-Referenz; der separate
Manifest-Negativcanary bleibt bewusst ohne Datenbankreferenz und wird erst
nach dem DB↔MinIO-Abgleich berücksichtigt. Der Drill prüft nach dem Restore
exakte ID und Zeit, Datensatzanzahlen, SHA-256-Manifeste der zentralen
Geschäftstabellen sowie den vollständigen Daten- und Strukturhash. Datenbank-
und Objekt-Canary werden anschließend aus der Quelle entfernt.

Die maschinenlesbare Evidenz wird zunächst in einem exklusiven, versteckten
Staging-Verzeichnis aufgebaut. `evidence.json`, ihr SHA-256-Sidecar und alle
Artefakte werden auf Symlinks und Vollständigkeit geprüft; erst danach wird
das gesamte Verzeichnis atomar veröffentlicht. Die Evidenz liegt unter
`storage/app/operations/evidence/restore/local-restore-*/evidence.json`.
Sie kann erneut geprüft werden:

```bash
docker compose exec -T laravel \
  php artisan erin:ops:restore-evidence:verify \
  /var/www/html/storage/app/operations/evidence/restore/<drill-id>/evidence.json \
  --json
```

Diese Evidenz trägt zwingend die Klassifikation
`LOCAL_SYNTHETIC_NOT_PRODUCTION_EVIDENCE`, enthält
`production_gate_eligible=false` und hält unabhängige Verifikation, DPO,
Legal sowie den echten Produktions-Restore offen. Sie darf deshalb niemals
direkt als Produktionsfreigabe eingetragen werden.

### Echter Produktions-Drill

1. Incident-Startzeit und freigegebenen Drill-Owner protokollieren.
2. Neueste laut RPO zulässige MySQL- und MinIO-Sicherung identifizieren.
3. Prüfsummen, Signaturen, Verschlüsselung und Backupalter prüfen.
4. Vollständig getrennte Restore-Umgebung ohne produktive ausgehende
   Nachrichten oder Webhooks bereitstellen.
5. MySQL in eine neue Datenbank wiederherstellen. Das vorhandene Hilfsskript
   darf ausschließlich eine temporäre Datenbank verwenden:

   ```bash
   ERIN_RESTORE_DRILL_CONFIRM=RESTORE_IN_TEMP_DATABASE \
     bash scripts/ops/database-restore-drill.sh \
     /sicherer/temporärer/pfad/erin-YYYYMMDDTHHMMSSZ.sql
   ```

6. MinIO/S3 inklusive benötigter Versionen in einen isolierten Bucket
   wiederherstellen. Niemals den Produktions-Bucket überschreiben.
7. Anwendung mit Restore-Daten und deaktivierten externen Integrationen
   starten.
8. Migrationstabelle, Mandantenzahlen, Benutzer-/Firmenbeziehungen,
   Bewerbungen, Audit-Timeline und Dokumentstichproben fachlich prüfen.
9. Signierte Downloads, Autorisierung und ClamAV-Status der Stichproben
   prüfen; keine reale E-Mail, Push-, Stripe- oder Zammad-Aktion auslösen.
10. RPO ab letztem wiederhergestellten Geschäftsvorfall und RTO ab
    Incident-Start bis zur fachlichen Nutzbarkeitsfreigabe messen.
11. Restore-Umgebung nach Evidenzsicherung kontrolliert löschen und Löschung
    protokollieren.
12. Eine zweite Person prüft Protokoll, Messwerte und Rollentrennung.

## Pflicht-Evidenz

Das unveränderliche Drillprotokoll enthält:

- Release-ID, Drill-ID, Start/Ende, Umgebung und Backup-IDs;
- Prüfsummen und Referenzen auf Schlüssel-/Zugriffsprotokolle, niemals
  Schlüssel selbst;
- Backupzeitpunkt und letzter fachlich wiederhergestellter Datensatz;
- Ziel- und Messwerte für MySQL- und Objekt-RPO/RTO;
- Anzahl geprüfter Tabellen, Datensätze, Objekte und Dateistichproben;
- Nachweis der Verschlüsselung und isolierten Wiederherstellung;
- Fehler, Abweichungen, Korrekturen und Wiederholungsergebnis;
- ausführende und unabhängig verifizierende Person;
- Löschbestätigung der Restore-Umgebung.

Die zugehörigen Umgebungsvariablen beginnen mit
`ERIN_BACKUP_`. `ERIN_BACKUP_ENCRYPTION_VERIFIED=true` und
`ERIN_BACKUP_ISOLATION_VERIFIED=true` dürfen erst nach realer Prüfung gesetzt
werden.

Die folgenden Zeitpunkte sind für MySQL und Objektstorage getrennt
verpflichtend:

- `ERIN_BACKUP_DB_CREATED_AT` und `ERIN_BACKUP_OBJECT_CREATED_AT`;
- `ERIN_BACKUP_DB_LAST_RESTORED_RECORD_AT` und
  `ERIN_BACKUP_OBJECT_LAST_RESTORED_RECORD_AT`;
- `ERIN_BACKUP_DB_RESTORED_AT` und `ERIN_BACKUP_OBJECT_RESTORED_AT`;
- `ERIN_BACKUP_DRILL_STARTED_AT` und `ERIN_BACKUP_DRILL_COMPLETED_AT`.

Das Gate berechnet das erreichte RPO als Zeitspanne zwischen Incident- bzw.
Drillstart und dem letzten wiederhergestellten fachlichen Datensatz. Der
Backupzeitpunkt liegt kausal dazwischen:
`letzter Datensatz ≤ Backup ≤ Drillstart ≤ Restore ≤ Abschluss`. Das erreichte
RTO ist die Zeitspanne zwischen Drillstart und dem jeweiligen
Restorezeitpunkt. Die
angegebenen Minutenwerte müssen exakt den auf volle Minuten aufgerundeten
Messwerten entsprechen und dürfen ihr jeweiliges Ziel nicht überschreiten.

Der lokale synthetische Lauf erstellt zuerst beide Backups und setzt danach
einen gesonderten simulierten Incident-/Drillstart. `operation_started_at`
dokumentiert zusätzlich den Beginn der technischen Vorbereitung und darf nicht
mit dem RTO-Start verwechselt werden.

Für ein grünes Produktionsgate sind außerdem
`ERIN_BACKUP_SCOPE=production`,
`ERIN_BACKUP_PRODUCTION_GATE_ELIGIBLE=true`,
`ERIN_BACKUP_INDEPENDENTLY_VERIFIED=true` sowie reale Start-, Abschluss- und
Verifikationszeitpunkte erforderlich. Diese Werte dürfen nicht aus dem lokalen
Drill übernommen werden.

## Technische Abdeckung

`scripts/ops/database-backup.sh` erzeugt einen restriktiv berechtigten
MySQL-Dump mit SHA-256-Prüfsumme.
`scripts/ops/database-restore-drill.sh` verifiziert die Prüfsumme, importiert in
eine neu erzeugte temporäre Datenbank, prüft die Migrationstabelle und löscht
die Datenbank anschließend.

`scripts/ops/local-encrypted-restore-drill.sh` ergänzt Verschlüsselung,
isolierten MySQL-/MinIO-Restore, RPO-/RTO-Messung, Manifestvergleich,
Negativkontrollen, sichere Bereinigung und maschinenlesbare Evidenz.

Keines dieser Skripte ersetzt eine verschlüsselte externe Produktionsablage,
die Wiederherstellung realer Produktionssicherungen, fachliche Stichproben
durch reale Verantwortliche oder eine unabhängige Verifikation.
