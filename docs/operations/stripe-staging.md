# Stripe-Staging und adversariale Abnahme

Erin verwendet Stripe Billing mit Checkout Sessions. Die Anwendung schaltet
Firmen nicht über den Browser-Redirect frei, sondern ausschließlich nach einem
signierten Webhook und einer erneuten Abfrage des kanonischen Abonnements.

## Sicherheitsgrenzen

- `erin:stripe:sync-plans` ist standardmäßig ein Dry-Run. `--apply` akzeptiert
  nur eindeutig erkannte `sk_test_…`-Schlüssel; Live-Mutationen benötigen
  zusätzlich Produktionsumgebung, öffentliche HTTPS-URL, `--allow-live` und
  eine interaktive Bestätigung.
- Product- und Price-IDs werden gemeinsam persistiert. Eine Price ohne Product,
  ein Product-Wechsel unter derselben Price oder mehrere Erin-Basispakete in
  einem Abonnement werden nicht automatisch übernommen.
- Webhooks benötigen eine gültige Stripe-Signatur innerhalb der Toleranz, eine
  gültige Ereignisstruktur und einen zur Umgebung passenden `livemode`.
- Wiederholte Ereignisse sind idempotent. Dieselbe Event-ID mit einem anderen
  Payload wird abgewiesen; verspätete Ereignisse können einen neueren
  Abonnementstand nicht überschreiben.
- Einmalige Visakäufe enthalten zusätzlich eine Erin-Signatur über Firma,
  Credit-Anzahl und Price-ID. Manipulierte Metadaten erzeugen keine Credits.
- Providerfehler werden in Ausgaben und Integration Receipts nur generisch
  gespeichert. Schlüssel und rohe Stripe-Fehlermeldungen werden dort nicht
  ausgegeben.

## Sichere Einrichtung

1. Ausschließlich Stripe-Testschlüssel in die nicht versionierte `.env`
   eintragen.
2. Katalog zunächst ohne Änderung prüfen:

   ```bash
   docker compose exec -T laravel php artisan erin:stripe:sync-plans
   ```

3. Fehlende Test-Produkte und immutable recurring Prices bewusst anlegen:

   ```bash
   docker compose exec -T laravel php artisan erin:stripe:sync-plans --apply --no-interaction
   ```

4. Die erzeugten Product- und Price-IDs über
   `STRIPE_PRODUCT_*`/`STRIPE_PRICE_*` in die geschützte
   Staging-Konfiguration übernehmen.
5. Nach Bereitstellung einer öffentlichen HTTPS-URL den Webhook über Cashier
   anlegen und dessen `whsec_…` ausschließlich im Secret Store hinterlegen.
6. Die vollständige read-only Prüfung ausführen:

   ```bash
   docker compose exec -T laravel php artisan erin:stripe:staging-check --remote --no-interaction
   ```

Die Remote-Prüfung liest nur Prices und Webhook-Endpunkte. Ohne öffentliche
HTTPS-URL oder Webhook-Secret bleibt sie absichtlich rot.

## Fehler- und Angriffsmatrix

| Bereich | Positive Prüfung | Negative Prüfungen |
|---|---|---|
| Schlüsselmodus | Test-Publishable- und Test-Secret-Key | gemischte Keys, Live-Key, unbekannter Prefix |
| Katalog | EUR, aktives Product/Price, korrekter Betrag und Laufzeit | falsches Product, deaktivierte Price, Betrag/Währung/Laufzeit abweichend |
| Webhook-Signatur | gültige Signatur innerhalb Toleranz | fehlend, falsch, älter als Toleranz, ungültiges JSON |
| Ereignismodus | `livemode=false` mit Test-Key | Liveevent in Test, Testevent in Live, fehlender boolescher Modus |
| Idempotenz | identischer Retry wird einmal verarbeitet | gleiche ID mit verändertem Payload, parallele Verarbeitung |
| Reihenfolge | kanonischer Stripe-Stand gewinnt | verspätetes Update, altes Ersatzabonnement, gleiche Sekunde |
| Abonnement | genau ein Erin-Basispaket plus Add-ons | fremde Firmenmetadaten, mehrere Basispakete, Product/Price-Drift |
| Tarifwechsel | Upgrade sofort mit Rechnung, Downgrade zur Verlängerung | gleicher/inaktiver/Enterprise-Tarif, Providerfehler vor lokaler Änderung |
| Kündigung | bis einschließlich 14 Tage vor Ende | Frist unterschritten verschiebt um eine volle Paketlaufzeit |
| Zusatzsitze | positive Ganzzahl und autorisierte Rolle | 0, negativ, Dezimalwert, über 100, Viewer/Recruiter |
| Visakauf | bezahlter Payment-Checkout mit Erin-Signatur | manipulierte Firma/Credits/Price, unpaid, falscher Modus, Replay |
| Kontingent | tarifgebundene Credits zuerst, danach gekaufte | abgelaufene Credits, Erschöpfung, doppelter PaymentIntent |
| Ausfälle | sicherer Retry mit identischem Ereignis | Timeout, HTTP 429, HTTP 5xx, keine Secrets in Antwort/Receipt |
| Missbrauchsschutz | normale Billing-Aktion | zu viele Versuche ergeben lokal HTTP 429 |

## Testbefehle

```bash
docker compose --profile tools run --rm pest \
  php artisan test \
  tests/Feature/Domain/BillingAdversarialTest.php \
  tests/Feature/Domain/StripeWebhookHttpTest.php \
  tests/Feature/Operations/StripePlanSyncCommandTest.php \
  tests/Feature/Operations/StripeStagingReadinessCommandTest.php

docker compose --profile tools run --rm phpstan
docker compose exec -T laravel vendor/bin/pint --test \
  app/Services/Billing app/Listeners/SyncStripePurchase.php \
  app/Http/Controllers/BillingController.php \
  app/Http/Controllers/Integrations/StripeWebhookController.php \
  tests/Feature/Domain/BillingAdversarialTest.php \
  tests/Feature/Domain/StripeWebhookHttpTest.php \
  tests/Feature/Operations/StripePlanSyncCommandTest.php \
  tests/Feature/Operations/StripeStagingReadinessCommandTest.php
```

Der lokale Testlauf verwendet Fakes und nimmt keine Stripe-Änderungen vor. Ein
echter Staging-Checkout benötigt zusätzlich eine öffentlich erreichbare
HTTPS-Instanz, ein Test-Webhook-Secret und Stripe-Testkarten.
