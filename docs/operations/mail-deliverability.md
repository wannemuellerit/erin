# Produktiver Mailversand

Erin verwendet einen getrennten Postmark- oder Resend-Server je Umgebung. Produktions-Credentials, Return-Path und Webhook-Secret dürfen nicht in Staging oder Pull-Request-Jobs verfügbar sein. Transaktionale Nachrichten laufen ausschließlich über die Queue; die Provider-Metadaten enthalten nur die interne Delivery-ID und den Ereignistyp, niemals Betreff oder Nachrichtentext.

## DNS- und Freigabecheck

Vor einem Go-live müssen SPF für den gewählten Return-Path, beide DKIM-Selectoren und eine DMARC-Policy mindestens im Beobachtungsmodus durch den Provider-Check als gültig gemeldet werden. Das Ergebnis, Zeitpunkt, Domain und verantwortliche Person werden als Launch-Evidence hinterlegt. Absender sind nach Zweck getrennt (`account@`, `notifications@`, `support@`); Marketingversand ist nicht Teil dieses Flows.

## Webhooks und Suppression

Der Edge-Webhook-Gateway signiert den unveränderten Body mit `HMAC-SHA256` und `MAIL_DELIVERY_WEBHOOK_SECRET` im Header `X-Erin-Mail-Signature`. Harte Bounces und Complaints sperren die normalisierte Empfängeradresse; Wiederholungen sind über Provider/Event-ID idempotent. Inhalt und Klartextadresse werden nicht gespeichert. Die betroffene Person erhält einen In-App-Hinweis. Eine Entsperrung erfolgt nur nach verifizierter Adresskorrektur und auditiertem Supportprozess.

## Alarmierung

`erin_mail_delivery_failures_24h` und `erin_mail_suppressed_recipients` werden ohne Empfänger- oder Nachrichteninhalte exportiert. Alarm: mehr als fünf harte Fehler in 15 Minuten oder Complaint-Rate über 0,1 %. Bei Provider-Ausfall bleiben Webrequests unbeeinflusst; Queue-Retries folgen 30 s, 2 min, 10 min und 30 min, anschließend greift der Failed-Job-Alarm.
