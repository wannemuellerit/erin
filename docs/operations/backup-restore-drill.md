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
Konsistenzverfahren verwenden und Stichproben in beide Richtungen prüfen:

- Datenbankeintrag verweist auf vorhandenes Objekt mit korrekter Prüfsumme;
- Objekt besitzt gültige Metadaten, Mandantenzuordnung und Zugriffsregel;
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

## Was technisch bereits vorhanden ist

`scripts/ops/database-backup.sh` erzeugt einen restriktiv berechtigten
MySQL-Dump mit SHA-256-Prüfsumme.
`scripts/ops/database-restore-drill.sh` verifiziert die Prüfsumme, importiert in
eine neu erzeugte temporäre Datenbank, prüft die Migrationstabelle und löscht
die Datenbank anschließend.

Diese Skripte ersetzen weder verschlüsselte externe Ablage noch den
MinIO-Restore, fachliche Stichproben, RPO-/RTO-Messung und unabhängige
Verifikation.
