# Zammad für lokale Entwicklung, Staging und Produktion

## Architektur

Zammad läuft als eigener Compose-Stack unter `docker/zammad/compose.yaml`. Der
Stack basiert auf dem offiziellen
[`zammad/zammad-docker-compose`-Release v15.5.6](https://github.com/zammad/zammad-docker-compose/releases/tag/v15.5.6)
und verwendet die dort zum Zeitpunkt der Übernahme festgelegten Images:

| Komponente | Lokale Version |
|---|---|
| Zammad | `7.1.1-0020` |
| Elasticsearch | `9.4.3` |
| PostgreSQL | `17.10-alpine` |
| Redis | `8.8-alpine` |
| Memcached | `1.6.44-alpine` |

Die Images werden nicht über `latest` bezogen. Updates erfolgen bewusst, indem
zuerst die Hinweise des neuen offiziellen Compose-Releases geprüft, anschließend
die Versionen in `docker/zammad/.env.example` aktualisiert und danach Backup und
Restore getestet werden.

Der separate Stack teilt nur das bereits vorhandene Docker-Netzwerk
`erin_default` mit Erin:

- Erin erreicht Zammad intern über `http://zammad:8080`.
- Der Zammad-Scheduler erreicht Erins lokalen Webhook über
  `http://laravel:8000/integrations/zammad/webhook`.
- Der Browser erreicht Zammad standardmäßig über
  `http://localhost:8090`. Der veröffentlichte Port ist dabei ausschließlich
  an `127.0.0.1` gebunden und nicht aus dem lokalen Netzwerk erreichbar.
- PostgreSQL, Redis, Elasticsearch und Memcached veröffentlichen keine
  Host-Ports und sind nicht Teil des Erin-Netzwerks.

Internes HTTP ist ausschließlich für `local` und `testing` vorgesehen. Staging
und Produktion müssen Zammad und den Erin-Callback über öffentliche HTTPS-URLs
ansprechen.

## Voraussetzungen

Die offizielle Zammad-Dokumentation verlangt mindestens 4 GB freien
Arbeitsspeicher und für Elasticsearch:

```bash
sudo sysctl -w vm.max_map_count=262144
```

Der Erin-Stack muss vor Zammad laufen, damit sein Netzwerk existiert:

```bash
docker compose up -d
```

Die vollständigen offiziellen Voraussetzungen stehen unter
[Install with Docker](https://docs.zammad.org/en/latest/install/docker-compose.html).

## Lokale Konfiguration

Die lokale Konfiguration wird nicht committed. Das folgende Skript kopiert die
Vorlage nach `docker/zammad/.env`, setzt Dateirechte auf `0600` und erzeugt
zufällige PostgreSQL- und Webhook-Secrets:

```bash
scripts/zammad/configure.sh
```

`ZAMMAD_BIND_ADDRESS=127.0.0.1` ist der sichere Standardwert. Ein abweichender
Wert darf nur bewusst gesetzt werden, wenn ein geschützter Reverse Proxy oder
eine gleichwertige Zugriffskontrolle vorgeschaltet ist. `0.0.0.0` darf nicht
für eine ungeschützte lokale Zammad-Instanz verwendet werden.

In diesem Modus wird beim ersten Browser-Aufruf der normale
Zammad-Einrichtungsassistent angezeigt. Dort legst du selbst E-Mail und Passwort
des ersten Administrators fest.

Optional lässt sich der offizielle Zammad-Autowizard verwenden:

```bash
scripts/zammad/configure.sh --with-admin
```

Das Skript fragt Administrator-E-Mail, Administrator-Passwort und die E-Mail
des technischen Erin-Benutzers verdeckt bzw. interaktiv ab. Es gibt keine
festen oder im Repository hinterlegten Zugangsdaten. Das Administrator-Passwort
wird als Base64-Autowizard-Payload nur in der ignorierten lokalen `.env`
zwischengespeichert und nach erfolgreichem Start automatisch entfernt.

Für nicht-interaktive Umgebungen müssen die Werte explizit als
Prozessvariablen bereitgestellt werden:

```bash
ZAMMAD_ADMIN_EMAIL='admin@example.com' \
ZAMMAD_ADMIN_PASSWORD='<Deployment-Secret>' \
ZAMMAD_INTEGRATION_EMAIL='erin-integration@example.com' \
scripts/zammad/configure.sh --with-admin
```

Das Passwort sollte dabei aus einem Secret-Store kommen und nicht als
Klartextbefehl in Shell-Historien landen.

## Start, Zustand und Logs

```bash
scripts/zammad/start.sh
```

Das Startskript prüft:

- sichere lokale Secrets,
- das gemeinsame Erin-Netzwerk,
- `vm.max_map_count`,
- den erfolgreichen Zammad-Init-Container,
- den Docker-Healthcheck des Zammad-Nginx,
- die HTTP-Erreichbarkeit auf Port `8090`.

Der erste Start lädt mehrere große Images und initialisiert Datenbank sowie
Suchindex. Das kann einige Minuten dauern. Separate Befehle:

```bash
scripts/zammad/healthcheck.sh
scripts/zammad/logs.sh
scripts/zammad/logs.sh zammad-init zammad-railsserver
scripts/zammad/stop.sh
```

`stop.sh` entfernt keine Volumes. Ein bewusstes `down --volumes` ist nicht Teil
der Skripte, weil es Daten unwiederbringlich löschen würde.

## Zammad-Login

Öffne:

```text
http://localhost:8090
```

- Mit Browser-Assistent: Verwende die dort selbst festgelegten Zugangsdaten.
- Mit `configure.sh --with-admin`: Verwende die beim Skript eingegebene
  Administrator-E-Mail und das dort eingegebene Passwort.
- Erin-Demokonten werden nicht automatisch zu Zammad-Konten.
- Das Erin-API-Token ist kein Browser-Passwort.

## Erin-Integration bootstrappen

Wenn der Browser-Assistent verwendet wurde, müssen danach diese zwei Werte in
der ignorierten Datei `docker/zammad/.env` ergänzt werden:

```dotenv
ZAMMAD_BOOTSTRAP_ADMIN_EMAIL=<E-Mail des ersten Zammad-Administrators>
ZAMMAD_INTEGRATION_EMAIL=<E-Mail des technischen Erin-Benutzers>
```

Danach:

```bash
scripts/zammad/bootstrap.sh
```

Der Bootstrap läuft direkt innerhalb des lokalen Zammad-Rails-Containers und:

1. prüft, ob der konfigurierte Benutzer wirklich Zammad-Administrator ist,
2. erstellt oder aktualisiert die Gruppe `Erin Support`,
3. erstellt einen dedizierten technischen Agenten mit Vollzugriff nur auf diese
   Gruppe,
4. legt einen neuen Token mit der Berechtigung `ticket.agent` parallel zum
   bisher gültigen Token an,
5. erstellt oder aktualisiert den signierten Webhook zum Erin-Callback,
6. erstellt oder aktualisiert einen selektiven Trigger, der ausschließlich
   Tickets der Gruppe `Erin Support` an Erin übermittelt,
7. schreibt die Erin-Konfiguration in die ignorierte Datei
   `docker/zammad/runtime/erin.env` und in Erins lokale `.env`,
8. leert den Laravel-Konfigurationscache, startet Laravel und Queue neu und
   prüft den neuen Token mit dem read-only Smoke-Test,
9. entfernt erst nach diesem erfolgreichen Test die älteren Erin-Tokens.

Gruppe, technischer Benutzer, Webhook, gruppenspezifischer Trigger und der neue
Token werden in einer Datenbanktransaktion geschrieben. Das bisherige Token
bleibt während Konfigurationswechsel, Containerneustart und Smoke-Test gültig;
erst der abschließende Finalisierungsschritt entfernt es. Scheitert ein Schritt
dazwischen, bleibt damit ein funktionierender Rückfall erhalten und ein
erneuter Bootstrap bereinigt vorbereitete Alt-Tokens nach erfolgreicher
Prüfung.

Der Token und das HMAC-Secret werden nicht auf der Konsole ausgegeben.
Wiederholtes Ausführen ist zulässig, rotiert aber absichtlich das technische
API-Token.

Anschließend:

```bash
docker compose exec -T laravel php artisan erin:zammad:smoke
```

Der Smoke-Test verwendet nur `GET /api/v1/users/me`. Er legt keine zusätzlichen
Tickets, Benutzer, Gruppen oder Artikel an.

## Automatisierter lokaler Ende-zu-Ende-Test

Nach dem Bootstrap kann der vollständige, schreibende Integrationspfad lokal
reproduzierbar geprüft werden:

```bash
scripts/zammad/e2e.sh
```

Das Skript ist in `production` gesperrt und führt nacheinander aus:

1. Eine verifizierte Demo-Fachkraft erstellt ein gekennzeichnetes Erin-Testticket
   mit PDF-Anhang.
2. Erin prüft den Anhang über ClamAV und überträgt Ticket und Datei im
   bestätigten Normal- und Replayfall idempotent an die Gruppe `Erin Support`.
3. Über das dedizierte technische Zammad-Token wird eine öffentliche
   Agentenantwort mit einem zweiten PDF erzeugt.
4. Der echte signierte Zammad-Webhook importiert Antwort und Anhang nach Erin.
5. Erin wartet auf den Queue-Import und prüft Zuordnung, Einmaligkeit, privaten
   Speicher, ClamAV-Freigabe und SHA-256-Inhalt.

Zustands- und Antwortdateien werden ausschließlich mit `0600` unter
`docker/zammad/runtime/` gespeichert. API-Token und Webhook-Secret erscheinen
nicht in der Konsolenausgabe. Der Test erzeugt bewusst lokale Testdaten in Erin
und Zammad; für eine reine Konfigurationsprüfung ist weiterhin
`erin:zammad:smoke` zu verwenden.

## Manueller Ende-zu-Ende-Test

1. In Erin als Firma ein Supportticket mit unkritischem Testinhalt anlegen.
2. Prüfen, ob Ticket und Eröffnungsnachricht im normalen Zustellpfad einmal in
   der Gruppe `Erin Support` auftauchen.
3. In Zammad eine öffentliche Agentenantwort verfassen.
4. Prüfen, ob sie einmal und ohne Neuladen im Erin-Supportchat erscheint.
5. Eine interne Zammad-Notiz verfassen und sicherstellen, dass sie in Erin nicht
   sichtbar wird.
6. Zammad vorübergehend stoppen, in Erin antworten, Zammad wieder starten und
   den persistierten Reconciliation- und Queue-Zustand prüfen.

Der Webhook nutzt die Zammad-Header `X-Zammad-Delivery` und
`X-Hub-Signature`. Da die Delivery-ID selbst nicht Bestandteil der
Zammad-Signatur ist, verwendet Erin den SHA-256-Hash des signierten Rohbodys
als Idempotenzschlüssel; eine ausgetauschte Delivery-ID kann einen alten Body
daher nicht erneut anwenden.

Für jedes zugeordnete Ticket muss Zammad außerdem `ticket.updated_at`
mitsenden. Erin speichert diesen Zeitpunkt als UTC-Epoch-Millisekunden unter
einer Datenbanksperre und übernimmt Betreff sowie Status nur von einem
nachweislich neueren Event. Dadurch kann ein verspätetes `open`-Event keinen
bereits neueren `closed`-Stand zurücksetzen.

Öffentliche Artikel werden nur übernommen, wenn `internal` exakt der boolesche
Wert `false` ist, `sender` exakt `Agent` oder `Customer` bezeichnet, eine
gültige Artikelzeit vorliegt und `article.ticket_id` mit der äußeren Ticket-ID
übereinstimmt. Interne Artikel benötigen ebenfalls eine gültige, passende
Ticket-ID, werden aber weder als Nachricht noch als Benachrichtigung
importiert. Das Webhook-Secret muss zur Laufzeit mindestens 32 Byte lang sein;
eine schwächere oder fehlende Konfiguration deaktiviert den Endpunkt
fail-closed mit HTTP 503.

Ticketzustand und Artikelchronologie besitzen getrennte Watermarks. Ein
verspätet gelieferter, aber tatsächlich neuerer Artikel darf damit
`last_reply_at` vorwärts bewegen, ohne einen bereits neueren Ticketstatus
zurückzusetzen. Gleich alte oder ältere Artikelzeiten bewegen
`last_reply_at` niemals rückwärts.

Ausgehende Artikel tragen einen HMAC-signierten Erin-Nachrichtenmarker, der
Ticket- und Nachrichten-ID bindet. `ZAMMAD_MESSAGE_MARKER_SECRET` kann diesen
Schlüssel vom Webhook-Schlüssel trennen; während einer Rotation gehören alte
Schlüssel in `ZAMMAD_PREVIOUS_MESSAGE_MARKER_SECRETS`, bis mindestens alle
laufenden Queue-Versuche und Zammad-Webhooks beendet sind. Ohne passenden
aktuellen oder vorherigen Schlüssel wird ein Marker niemals als
Zustellbestätigung akzeptiert.

## Ausgehende Zustellung und Reconciliation

Vor jedem erneuten schreibenden Zammad-Aufruf speichert Erin eine
`external_reconcile_not_before`-Frist in MySQL. Nach einem Timeout oder
ungeklärten Queue-Abbruch liest Erin erst nach dieser Frist in Zammad und
wiederholt diese Suche standardmäßig dreimal. Erst wenn alle konfigurierten
Lesungen den signierten Erin-Marker, die erwartete Absenderrolle, den
`internal`-Status und den normalisierten Inhalt nicht finden, wird erneut
geschrieben. Ein minütlicher Scheduler nimmt auch nach Worker-Neustarts alle
fälligen `syncing`-/`failed`-Datensätze wieder auf.

Die Grenzen sind bewusst als **at least once** dokumentiert: Zammad bietet für
diese Schreiboperationen keinen von Erin kontrollierten Idempotency-Key. Ein
Artikel kann nach erfolgreicher Provider-Annahme noch nicht in den
Reconciliation-Leseendpunkten sichtbar sein. Nach allen negativen Lesungen
kann Erin deshalb erneut senden und in diesem seltenen Konsistenzfenster einen
doppelten Zammad-Datensatz erzeugen. Persistierte Fristen, mehrere Lesungen,
HMAC-Marker und Webhook-Korrelation verkleinern dieses Fenster, sind aber keine
Exactly-once-Garantie.

Ticket-ID und Eröffnungsartikel-ID werden lokal in einer gemeinsamen
Datenbanktransaktion gespeichert. Fehlt eine der beiden IDs oder kollidiert
sie mit einer vorhandenen Zuordnung, wird keine halbe Zuordnung committed.
Empfangene Artikel-IDs werden zusätzlich dauerhaft in
`support_zammad_article_receipts` gespeichert; ein Webhook mit mehreren
`article_ids` importiert alle bisher ungesehenen Artikel chronologisch und
überspringt interne Notizen weiterhin vollständig in der Nutzeransicht.

## Dauerhafte Webhook-Outbox

Nachrichten, Anhangimporte, Live-Broadcasts und Benachrichtigungskanäle werden
in derselben Datenbanktransaktion wie der eingehende Artikel in
`support_webhook_outbox` gespeichert. Erst danach werden sie an den
Queue-Worker übergeben. Ist Redis direkt nach dem Commit nicht erreichbar,
bleiben Nachricht und Effekte erhalten; ein identischer Webhook-Replay und der
minütliche Scheduler stoßen alle fälligen Einträge erneut an. Gesperrte
Einträge werden nach zehn Minuten als verwaist betrachtet und wieder
übernehmbar.

Ein Zammad-Anhang gilt erst dann als zugestellt, wenn Download, Allowlist,
privater Storage und ClamAV-Prüfung im Outbox-Worker einen terminalen Zustand
erreicht haben. Der Outbox-Eintrag bestätigt nicht lediglich einen zweiten
Queue-Push. Geht die Bestätigung nach erfolgreichem Import verloren, erkennt
der Retry den terminalen Zustand und sendet noch einmal ein stabiles
Live-Update, ohne die Datei erneut herunterzuladen.

Benachrichtigungen besitzen getrennte Outbox-Effekte für Datenbank, Broadcast,
E-Mail und Browser-Push:

- Der Datenbankkanal ist durch eine deterministische Notification-UUID exakt
  idempotent.
- Live-Broadcasts verwenden dieselbe UUID; die Erin-Glocke verwirft bereits
  bekannte IDs. Supportchat-Nachrichten werden zusätzlich über ihre stabile
  Nachrichten-ID zusammengeführt.
- E-Mail und Browser-Push werden pro Kanal dauerhaft erneut versucht. Bei
  einem Prozessabbruch genau zwischen externer Annahme und lokaler
  Bestätigung gelten sie technisch als **at least once** und können in diesem
  seltenen Fehlerfenster doppelt eintreffen. Diese Grenze darf im Betrieb
  nicht als Exactly-once-Garantie dargestellt werden.

`support_webhook_outbox` sollte im Monitoring auf alte unverarbeitete Einträge,
steigende Versuchszahlen und `last_error` geprüft werden. Ein Queue-Purge darf
nicht als Fehlerbehebung verwendet werden; nach einem Ausfall wird der
Scheduler wieder gestartet und die Outbox kontrolliert abgearbeitet.

## Frühe Webhooks und lokale Zuordnung

Ein signierter Zammad-Webhook kann Erin erreichen, bevor die Antwort auf das
Erstellen des zugehörigen Tickets die externe ID lokal gespeichert hat. Erin
legt diesen Body deshalb vorübergehend in
`support_zammad_webhook_inbox` ab. Die Deduplizierung erfolgt über den
SHA-256-Wert des bereits verifizierten Bodys; wechselnde Delivery-Header
erzeugen keine zweite Inbox-Zeile. Pro Body werden höchstens acht
Delivery-Aliase gespeichert; weitere Aliase brechen fail-closed ab. Nach
Verarbeitung oder Terminalisierung werden für Replays keine neuen Aliase mehr
persistiert. Wird eine bereits gespeicherte Delivery-ID für einen anderen Body
wiederverwendet, bricht Erin ebenfalls fail-closed ab.

Nach der Ticketzuordnung wird der unveränderte Body erneut durch dieselbe
Webhook-Validierung verarbeitet. Vorher wird seine Prüfsumme nochmals
verglichen. Verarbeitete, terminal abgelehnte und abgelaufene Einträge behalten
nur nicht-sensible Metadaten; `raw_payload` wird sofort geleert. Einträge ohne
lokale Zuordnung werden nach
`ZAMMAD_UNMATCHED_WEBHOOK_RETENTION_HOURS` Stunden terminalisiert. Der
Standardwert beträgt 24 Stunden. Monitoring muss offene Inbox-Einträge,
Versuchszahlen, `terminal_at` und `last_error` berücksichtigen.

## Anhänge

Der Erin-Dateipfad für Supportanhänge ist vollständig umgesetzt:

- Uploads werden anhand einer festen Erweiterungs- und MIME-Allowlist geprüft.
- Standardmäßig sind höchstens acht Dateien mit jeweils maximal 10 MB und
  zusammen maximal 15 MB zulässig.
- Dateien liegen ausschließlich auf dem privaten Storage-Disk.
- Jeder Upload bleibt bis zum erfolgreichen ClamAV-Scan gesperrt.
- Infizierte, fehlende, veränderte oder nicht vollständig geprüfte Dateien
  werden fail-closed weder ausgeliefert noch an Zammad übertragen.
- Erin-Anhänge werden nach erfolgreicher Prüfung mit Dateiname, MIME-Typ und
  Inhalt an Zammad übertragen.
- Zammad-Anhänge verwenden den deterministischen privaten Pfad
  `support-tickets/{ticket}/zammad/{attachment}.{extension}`. Ein Retry
  überschreibt damit denselben Zielpfad statt weitere Kopien zu erzeugen.
- Scheitert die Datenbankreferenz nach der Dateiablage, entfernt der Worker die
  Datei sofort. Für harte Prozessabbrüche löscht der tägliche Scheduler
  abgelaufene, nicht referenzierte deterministische Dateien nach der
  konfigurierten Karenzzeit.

Der Cleanup kann vor einem produktiven Lauf ohne Löschung geprüft werden:

```bash
docker compose exec -T laravel php artisan erin:support:prune-orphan-attachments --json
docker compose exec -T laravel php artisan erin:support:prune-orphan-attachments --execute --json
```

Metadaten- oder Löschfehler liefern dabei einen von null verschiedenen
Exit-Code. Der Schedulerlauf gilt in diesem Fall als fehlgeschlagen und muss
alarmiert werden.

- Zammad-Anhänge werden über die authentifizierte API importiert, privat
  gespeichert und ebenfalls durch ClamAV geprüft.
- Downloads erfordern die Mandanten-/Supportberechtigung und eine kurzlebig
  signierte URL; die Standardgültigkeit beträgt zehn Minuten.
- Größe und SHA-256-Prüfsumme werden vor der Übertragung erneut validiert.

Die konkreten Grenzen werden über `SUPPORT_ATTACHMENT_*` konfiguriert. Nach
Änderungen müssen positive und negative Upload-Fälle, Malware-Erkennung,
fehlende Storage-Objekte, abgelaufene Signaturen sowie Import und Export mit
Zammad erneut getestet werden.

## Staging und Produktion

Die lokalen HTTP-Werte dürfen nicht übernommen werden. Erforderlich sind:

```dotenv
ZAMMAD_ENABLED=true
ZAMMAD_URL=https://support.example.com
ZAMMAD_TOKEN=<Deployment-Secret>
ZAMMAD_GROUP=Erin Support
ZAMMAD_WEBHOOK_SECRET=<mindestens 32 zufällige Zeichen>
ZAMMAD_TIMEOUT=10
ZAMMAD_RECONCILE_INITIAL_DELAY_SECONDS=30
ZAMMAD_RECONCILE_INTERVAL_SECONDS=15
ZAMMAD_RECONCILE_REQUIRED_MISSES=3
ZAMMAD_UNMATCHED_WEBHOOK_RETENTION_HOURS=24
ZAMMAD_ALLOW_LOCAL_HTTP=false
ZAMMAD_LOCAL_HTTP_HOSTS=
ZAMMAD_WEBHOOK_CALLBACK_URL=https://app.example.com/integrations/zammad/webhook
```

In Zammad muss der Webhook auf die öffentliche Erin-HTTPS-Adresse zeigen,
SSL-Prüfung muss aktiv sein und der Signatur-Token muss exakt dem
`ZAMMAD_WEBHOOK_SECRET` entsprechen. Das technische Token gehört in den
Deployment-Secret-Store; persönliche Admin-Token sind nicht zulässig.

## Backup und Restore

Der offizielle Stack schreibt zeitgesteuerte Zammad-Backups in das benannte
Volume `zammad-backup`. Das allein ist noch kein belastbares Backup: Für
Staging und Produktion muss dieses Volume regelmäßig verschlüsselt auf ein
zweites System kopiert und ein echter Restore in einer isolierten Umgebung
nachgewiesen werden.

Ein Restore-Drill beantwortet konkret:

- Können PostgreSQL-Daten, Zammad-Dateispeicher und Suchindex aus dem Backup
  wiederhergestellt werden?
- Wie alt dürfen verlorene Daten maximal sein (RPO)?
- Wie lange darf die Wiederherstellung dauern (RTO)?
- Wer führt sie aus und wo liegt das geprüfte Protokoll?

Siehe außerdem die offizielle
[Zammad Docker Backup-&-Restore-Dokumentation](https://docs.zammad.org/en/latest/appendix/backup-and-restore-docker.html).
