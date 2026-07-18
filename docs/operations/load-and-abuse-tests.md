# Last- und Missbrauchstests

Diese Tests prüfen die technische Obergrenze des Kandidatenimports und typische Fehlerpfade. Sie ersetzen keinen verteilten Lasttest mit produktionsähnlicher Infrastruktur, liefern aber einen reproduzierbaren Mindeststandard für jeden Release-Kandidaten.

## Automatisierter Lauf

```bash
bash scripts/ops/test-import-load.sh
```

Der Lauf prüft:

- exakt 500 Datensätze als CSV und XLSX;
- atomaren Abbruch beim 501. XLSX-Datensatz;
- doppelte und formelbasierte Tabellenwerte über die bestehenden Importtests;
- Fail-closed-Verhalten bei fehlenden privaten Objekten;
- Löschung der Quarantänedatei, wenn ClamAV Malware meldet oder nicht erreichbar ist;
- Queue-Rückstau gegen `ERIN_QUEUE_MAX_PENDING` und `ERIN_QUEUE_MAX_FAILED`.

Die Testdateien entstehen ausschließlich temporär. XLSX-Dateien werden über denselben OpenSpout-Pfad erstellt und gelesen, den die Anwendung verwendet.

Kandidatenimporte laufen auf der Queue `low` und werden pro Import-ID für eine Stunde dedupliziert. Dadurch verdrängen große Dateien keine interaktiven Jobs und ein versehentlicher Doppeldispatch verarbeitet dieselbe Datei nicht parallel.

## Produktiver Queue-Rückstau

```bash
docker compose exec -T laravel php artisan erin:ops:queue-health --json
```

Der Command liefert Exit-Code `1`, sobald ein Grenzwert überschritten ist oder die Queue nicht geprüft werden kann. Der Scheduler führt ihn alle fünf Minuten aus und schreibt das strukturierte Log-Event `ops.queue_health`.

Für einen realistischen Belastungstest müssen in einer isolierten Stagingumgebung zusätzlich mehrere parallele Importe eingestellt werden. Dabei sind mindestens Queue-Tiefe, ältester Job, Laufzeit pro 500er-Import, Datenbank-Locks, Redis-Speicher, Worker-Neustarts und fehlgeschlagene Jobs zu beobachten.

## ClamAV und MinIO/S3

Die aktive technische Probe lautet:

```bash
docker compose exec -T laravel php artisan erin:ops:readiness --probe --json
```

Sie schreibt eine zufällige Testdatei in den privaten Storage, liest sie zurück und löscht sie anschließend. Zusätzlich sendet sie einen sauberen Stream an ClamAV.

Vor einem öffentlichen Start ist in Staging außerdem die offizielle EICAR-Testdatei zu verwenden. Erwartet wird eine Ablehnung ohne Datenbankeintrag und ohne verbleibendes Objekt; die Testdatei darf niemals in Produktion oder in ein Backup übernommen werden.

Folgende Storage-Randfälle bleiben Teil des manuellen Staging-Laufs:

- MinIO während Upload, Scan und Queue-Verarbeitung kurzzeitig stoppen;
- Objekt zwischen Upload und Worker-Ausführung entfernen;
- ungültige Zugangsdaten und abgelaufene Schlüssel testen;
- Schreibrecht erlauben, Löschrecht entziehen und den Alarmweg prüfen;
- öffentliche Bucket-Policies und anonyme Downloads nachweislich ausschließen;
- sehr langsame Streams und die maximale Uploadgröße testen.
