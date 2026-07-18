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
4. rotiert dessen Token mit der Berechtigung `ticket.agent`,
5. erstellt oder aktualisiert den signierten Webhook zum Erin-Callback,
6. erstellt oder aktualisiert einen selektiven Trigger, der ausschließlich
   Tickets der Gruppe `Erin Support` an Erin übermittelt,
7. schreibt die Erin-Konfiguration in die ignorierte Datei
   `docker/zammad/runtime/erin.env` und in Erins lokale `.env`,
8. leert den Laravel-Konfigurationscache und startet Laravel und Queue neu.

Gruppe, technischer Benutzer, Webhook, gruppenspezifischer Trigger und
Tokenrotation werden in einer gemeinsamen Datenbanktransaktion geschrieben.
Schlägt ein Schritt fehl, bleiben damit auch das zuvor gültige Token und die
bestehende Integration unverändert. Der neue Token wird erst nach erfolgreichem
Commit ausgegeben und anschließend ausschließlich in die ignorierten lokalen
Konfigurationsdateien geschrieben.

Der Token und das HMAC-Secret werden nicht auf der Konsole ausgegeben.
Wiederholtes Ausführen ist zulässig, rotiert aber absichtlich das technische
API-Token.

Anschließend:

```bash
docker compose exec -T laravel php artisan erin:zammad:smoke
```

Der Smoke-Test verwendet nur `GET /api/v1/users/me`. Er legt keine zusätzlichen
Tickets, Benutzer, Gruppen oder Artikel an.

## Manueller Ende-zu-Ende-Test

1. In Erin als Firma ein Supportticket mit unkritischem Testinhalt anlegen.
2. Prüfen, ob Ticket und Eröffnungsnachricht genau einmal in der Gruppe
   `Erin Support` auftauchen.
3. In Zammad eine öffentliche Agentenantwort verfassen.
4. Prüfen, ob sie genau einmal und ohne Neuladen im Erin-Supportchat erscheint.
5. Eine interne Zammad-Notiz verfassen und sicherstellen, dass sie in Erin nicht
   sichtbar wird.
6. Zammad vorübergehend stoppen, in Erin antworten, Zammad wieder starten und
   die genau einmalige Queue-Zustellung prüfen.

Der Webhook nutzt die Zammad-Header `X-Zammad-Delivery` und
`X-Hub-Signature`. Erin lehnt fehlende, manipulierte oder bereits verarbeitete
Zustellungen ab.

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
