# Stripe-Staging und adversariale Abnahme

Faden verwendet Stripe Billing mit Checkout Sessions. Die Anwendung schaltet
Firmen nicht über den Browser-Redirect frei, sondern ausschließlich nach einem
signierten Webhook und einer erneuten Abfrage des kanonischen Abonnements.

## Sicherheitsgrenzen

- `erin:stripe:sync-plans` ist standardmäßig ein Dry-Run. `--apply` akzeptiert
  nur eindeutig erkannte `sk_test_…`-Schlüssel; Live-Mutationen benötigen
  zusätzlich Produktionsumgebung, öffentliche HTTPS-URL, `--allow-live` und
  eine interaktive Bestätigung.
- Product- und Price-IDs werden gemeinsam persistiert. Eine Price ohne Product,
  ein Product-Wechsel unter derselben Price oder mehrere Faden-Basispakete in
  einem Abonnement werden nicht automatisch übernommen.
- Webhooks benötigen eine gültige Stripe-Signatur innerhalb der Toleranz, eine
  gültige Ereignisstruktur und einen zur Umgebung passenden `livemode`.
- Wiederholte Ereignisse sind idempotent. Dieselbe Event-ID mit einem anderen
  Payload wird abgewiesen; verspätete Ereignisse können einen neueren
  Abonnementstand nicht überschreiben.
- Einmalige Visakäufe enthalten zusätzlich eine Faden-Signatur über Firma,
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
| Abonnement | genau ein Faden-Basispaket plus Add-ons | fremde Firmenmetadaten, mehrere Basispakete, Product/Price-Drift |
| Tarifwechsel | Upgrade sofort mit Rechnung, Downgrade zur Verlängerung | gleicher/inaktiver/Enterprise-Tarif, Providerfehler vor lokaler Änderung |
| Kündigung | bis einschließlich 14 Tage vor Ende | Frist unterschritten verschiebt um eine volle Paketlaufzeit |
| Zusatzsitze | positive Ganzzahl und autorisierte Rolle | 0, negativ, Dezimalwert, über 100, Viewer/Recruiter |
| Visakauf | bezahlter Payment-Checkout mit Faden-Signatur | manipulierte Firma/Credits/Price, unpaid, falscher Modus, Replay |
| Kontingent | tarifgebundene Credits zuerst, danach gekaufte | abgelaufene Credits, Erschöpfung, doppelter PaymentIntent |
| Ausfälle | sicherer Retry mit identischem Ereignis | Timeout, HTTP 429, HTTP 5xx, keine Secrets in Antwort/Receipt |
| Missbrauchsschutz | normale Billing-Aktion | zu viele Versuche ergeben lokal HTTP 429 |

## Vollständiger Stripe-Test-Clock-Acceptance-Run

Dieser Run wird in einem isolierten Stripe-Sandbox-Workspace ausgeführt. Stripe
bezeichnet Test Clocks im Dashboard inzwischen auch als „Simulations“; sie
verschieben die Zeit kontrolliert und lösen dabei echte Billing-Webhooks aus
([Stripe-Dokumentation](https://docs.stripe.com/billing/testing/test-clocks)).

1. `erin:stripe:staging-check --remote --no-interaction` muss grün sein. In
   Workbench müssen mindestens Subscription-, Subscription-Schedule-, Invoice-,
   Checkout- und `charge.refunded`-Events an `/billing/webhook` aktiviert sein.
2. Eine neue Erin-Testfirma mit vollständiger Rechnungsadresse und gültiger
   Test-USt-ID anlegen, Basic buchen und im Checkout einen aktiven Promotion-Code
   verwenden. Erst nach `customer.subscription.*` und `invoice.paid` dürfen
   Portalzugriff und Kontingente aktiv sein; Rechnung, Rabatt, Steuer und Tax-ID
   müssen unter „Paket & Abrechnung“ erscheinen.
3. Im Stripe-Dashboard am erzeugten Testabonnement „Run simulation“ starten.
   Zur Mitte der Basic-Laufzeit auf Business upgraden und Zusatzsitze buchen.
   Die anteilige Rechnung muss genau einmal erscheinen; ein Webhook-Retry darf
   weder Rechnung noch Sitze verdoppeln.
4. Ein Visa-Paket kaufen. Vor dem `checkout.session.completed`-Webhook darf kein
   Credit sichtbar sein. Anschließend den zugehörigen Test-Charge vollständig
   erstatten und prüfen, dass der nicht verfallende Kaufbestand wieder auf null
   steht. `charge.refunded` vor dem Checkout-Event in einer zweiten Simulation
   muss zum selben Endzustand führen.
5. Eine fehlgeschlagene Testzahlung als Standardzahlmethode setzen und die Uhr
   bis zur Verlängerung vorziehen. `invoice.payment_failed`/`past_due` müssen als
   Warnzustand erscheinen und dürfen keine neuen Kontingente freischalten. Nach
   erfolgreicher Nachzahlung müssen Rechnung und Status genau einmal auf
   „bezahlt/aktiv“ wechseln.
6. Premium als Downgrade vormerken und bis zum Renewal vorziehen: Bis dahin
   bleiben Preisversion und Entitlements unverändert, danach wechseln beide
   gemeinsam. Anschließend einmal mehr als 14 Tage und einmal weniger als 14
   Tage vor Laufzeitende kündigen; der zweite Fall muss um eine volle
   Tariflaufzeit verschoben werden.
7. Für jeden Schritt Stripe-Event-ID, Test-Clock-ID, Erin-Firma, sichtbaren
   Rechnungsstatus und erwartete Kontingente im Abnahmeprotokoll festhalten.
   Danach `erin:stripe:reconcile-billing --no-interaction` ausführen; Exitcode 0
   und keine manuelle Review sind Abschlussbedingung.

Der Dashboard-Flow folgt Stripes dokumentiertem Simulationsablauf; eine Clock
kann jeweils nur begrenzt über Abrechnungsintervalle vorgezogen werden
([Stripe: Subscriptions simulieren](https://docs.stripe.com/billing/testing/test-clocks/simulate-subscriptions)).

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
