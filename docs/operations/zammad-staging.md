# Zammad für Staging und Produktion

## Architektur und Login

Zammad ist kein Bestandteil des lokalen Docker-Compose-Stacks. Erin bindet eine
separat betriebene oder gemanagte Zammad-Instanz über HTTPS an. Deshalb existiert
lokal weder ein Zammad-Container noch ein zusätzlicher lokaler Login.

Der Browser-Login erfolgt direkt unter der in `ZAMMAD_URL` hinterlegten URL mit
einem separat in Zammad angelegten Benutzerkonto. Das in Erin konfigurierte
`ZAMMAD_TOKEN` ist ausschließlich für die REST-API bestimmt und ist kein
Browser-Passwort. Erin-Demozugänge funktionieren nicht in Zammad.

In der aktuellen lokalen `.env` sind noch keine Zammad-Werte hinterlegt. Ohne
eine externe Instanz, deren URL und ein dort angelegtes Benutzerkonto ist daher
noch kein Zammad-Login möglich. Bei einer neuen Zammad-Instanz wird zuerst der
Zammad-Einrichtungsassistent im Browser abgeschlossen und dort das erste
Administratorkonto angelegt; Erin erzeugt keine Zammad-Administratoren.

## Dedizierten Integrationsbenutzer vorbereiten

1. In Zammad einen eigenen technischen Agenten für Erin anlegen.
2. Dem Agenten nur die für Tickets und die konfigurierte Gruppe erforderlichen
   Rechte geben. Keine persönlichen Admin-Zugänge oder globale Admin-Token in
   Erin hinterlegen.
3. Im Profil des technischen Agenten ein eigenes API-Token erzeugen. Zammad
   begrenzt ein Token auf die Rechte des erzeugenden Benutzers:
   [Zammad-Berechtigungen für API-Token](https://admin-docs.zammad.org/en/latest/manage/roles/permissions.html).
4. Token unmittelbar als Deployment-Secret hinterlegen. Es darf weder
   committed noch in Tickets, Screenshots oder Logs kopiert werden.

## Erin konfigurieren

```dotenv
ZAMMAD_ENABLED=true
ZAMMAD_URL=https://support.example.com
ZAMMAD_TOKEN=<Deployment-Secret>
ZAMMAD_GROUP=Erin Support
ZAMMAD_WEBHOOK_SECRET=<mindestens 32 zufällige Zeichen>
ZAMMAD_TIMEOUT=10
```

Zusätzlich muss `APP_URL` auf die öffentlich erreichbare HTTPS-Adresse der
Erin-Stagingumgebung zeigen. Nach einer Änderung an Umgebungsvariablen:

```bash
docker compose exec -T laravel php artisan optimize:clear
docker compose restart laravel queue
```

## Read-only Smoke-Test

```bash
docker compose exec -T laravel php artisan erin:zammad:smoke
```

Der Befehl prüft lokal:

- Aktivierung, sichere HTTPS-URLs und vorhandene Konfigurationswerte,
- eine Mindestlänge von 32 Zeichen für das Webhook-Secret,
- die registrierte Erin-Callback-Route,
- HMAC-SHA1-Erzeugung und Ablehnung manipulierter Payloads,
- Erreichbarkeit und Token-Authentifizierung über ausschließlich
  `GET /api/v1/users/me`.

Der Smoke-Test legt keine Tickets, Benutzer, Gruppen oder Artikel an. Er folgt
keinen HTTP-Weiterleitungen und gibt weder Token, Secret, Benutzerkennung noch
Antworttexte von Zammad aus. Der verwendete read-only Endpunkt ist in der
[Zammad-User-API](https://docs.zammad.org/en/latest/api/user.html#me-current-user)
dokumentiert.

## Webhook in Zammad einrichten

In Zammad unter `Manage → Webhooks` einen regulären, aktiven Webhook mit
folgenden Werten anlegen:

- Methode: `POST`
- Endpoint: `https://<erin-domain>/integrations/zammad/webhook`
- SSL-Prüfung: aktiv
- HMAC-SHA1-Signatur-Token: exakt derselbe geheime Wert wie
  `ZAMMAD_WEBHOOK_SECRET`

Anschließend einen Trigger für relevante öffentliche Ticketänderungen und
Agentenantworten erstellen und den Webhook als Aktion auswählen. Das alleinige
Anlegen eines Webhooks löst noch keine Zustellung aus. Siehe
[Zammad: Webhooks hinzufügen](https://admin-docs.zammad.org/en/latest/manage/webhook/add.html).

Erin erwartet pro Zustellung die von Zammad dokumentierten Kopfzeilen
`X-Zammad-Delivery` und `X-Hub-Signature`. Die eindeutige Delivery-ID schützt
Wiederholungen vor doppelter Verarbeitung; die Signatur schützt die Integrität
des unveränderten Request-Bodys. Siehe
[Zammad: Webhook-Payload und Request-Header](https://admin-docs.zammad.org/en/latest/manage/webhook/payload.html).
Der vollständige Empfang dieser Header kann erst mit einem ausgelösten
Staging-Webhook geprüft werden und ist daher nicht Teil des read-only
Smoke-Tests.

## Manuelle Staging-Abnahme

1. `erin:zammad:smoke` muss erfolgreich sein.
2. In Erin als Firma ein Supportticket mit unkritischem Testinhalt anlegen.
3. Prüfen, ob Ticket und erster Artikel genau einmal in Zammad erscheinen.
4. In Zammad öffentlich antworten und prüfen, ob die Antwort genau einmal und
   ohne Neuladen im Erin-Supportchat erscheint.
5. Zammad kurzzeitig unerreichbar machen, eine Antwort in Erin erfassen und
   prüfen, ob die Queue nach Wiederherstellung ohne Duplikat synchronisiert.
6. Interne Zammad-Notizen dürfen im Firmenportal nicht erscheinen.

Zammad-Anhänge werden derzeit ausschließlich als bereinigte Metadaten
übernommen; Erin lädt den Dateiinhalt noch nicht herunter und bietet deshalb
bewusst keinen Download an. Ein vollständiger Anhangsfluss benötigt vor der
Freigabe Größen- und Typgrenzen, ClamAV-Prüfung, privaten Storage, autorisierte
signierte Downloads und eigene Missbrauchstests.

Nach der Abnahme Testtickets löschen oder gemäß der vereinbarten
Aufbewahrungsrichtlinie schließen. Zugangsdaten und Payloads gehören nicht in
das Abnahmeprotokoll.
