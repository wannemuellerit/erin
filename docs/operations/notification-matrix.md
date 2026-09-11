# Benachrichtigungsmatrix

Erin stellt Produktbenachrichtigungen über In-App/Broadcast, E-Mail und optional Browser-Push zu. Jede Person kann die Kanäle pro Kategorie deaktivieren; SMS und WhatsApp bleiben im Deutschland-MVP technisch gesperrt.

| Zielgruppe | Ereignis | Kategorie | Deep Link |
| --- | --- | --- | --- |
| Firma | Neue Bewerbung | `application.created` | Pipeline der Stelle |
| Kandidat:in | Bewerbungsstatus | `application.status_changed` | Bewerbungen |
| Gegenpartei | Interview vorgeschlagen, beantwortet, bestätigt oder abgesagt | `interview.*` | Interviews |
| Gesprächsteilnehmende | Neue Nachricht | `message.received` | Nachrichtencenter |
| Kandidat:in | Dokumententscheidung | `document.reviewed` | Profil |
| Kandidat:in | Visa-Schritt oder bevorstehende Frist | `visa.*` | Dashboard |
| Empfehlende Person | Referral-Status oder Auszahlungsreife | `referral.*` | Empfehlungen |
| Ticketbeteiligte | Supportantwort | `support.reply` | Support |
| Firma | Zahlungswarnung | `billing.payment_warning` | Abrechnung |
| Firma | Boost wieder verfügbar | `boost.available` | Stellenanzeigen |
| Firma | Einladung angenommen | `company.invitation_accepted` | Team |
| Gewählte Zielgruppe | Plattformhinweis | `system.platform_announcement` | freigegebener Erin-Pfad |

## Zustell- und Datenschutzregeln

- `ProductNotificationDispatcher` akzeptiert ausschließlich dokumentierte Ereignisse und Same-Origin-Ziele.
- Die Kombination aus Person, Ereignis und fachlichem Schlüssel ist eindeutig. Wiederholte Webhooks, Scheduler-Läufe oder Form-Retries erzeugen daher keine Duplikate.
- Das Zustell-Ledger enthält nur Ereignis, gehashten Idempotenzschlüssel, Status, Versuche und Fehlerklasse – niemals Titel, Nachricht, Dokumentnamen oder Identitätsdaten.
- Zustellfehler werden ohne Nutzinhalt protokolliert und bleiben über Queue-Fehler sowie `notification_deliveries` betrieblich sichtbar.
- Browser-Abos gehören genau einem Konto; Schlüsselrotation aktualisiert dasselbe Endpoint-Objekt. Der Service Worker öffnet ausschließlich Links auf dem aktuellen Erin-Origin.

## Betrieb

Der Scheduler führt `erin:notifications:operational` stündlich für Visa-Fristen und abgelaufene Boosts aus. Super-Admins können im Bereich **System** einen zweisprachigen Plattformhinweis an alle aktiven Konten, Kandidat:innen oder Firmen senden; Empfängerpräferenzen bleiben wirksam und der Versand wird auditiert.
