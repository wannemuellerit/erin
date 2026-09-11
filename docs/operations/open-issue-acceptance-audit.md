# Resilienz-Audit aller GitHub-Issues

Stand: 10. August 2026

Quelle: alle 58 offenen und geschlossenen Issues in `wannemuellerit/erin`
einschliesslich Epic #56.

Ein Issue gilt in diesem Audit nur dann als abgeschlossen, wenn jedes
Akzeptanzkriterium entweder durch ausführbaren Code und einen grünen Test oder
durch einen überprüfbaren externen Nachweis belegt ist. Ein vorhandener
Feature-Flag, Provider-Fake oder Runbook ersetzt keine ausdrücklich verlangte
Produktions-, Rechts-, DNS-, Partner- oder Game-Day-Freigabe.

Die Bewertung geht bewusst über die Checkboxen der Issues hinaus. Für jede
Funktionsgruppe werden ein Happy Path sowie die jeweils sinnvollen Fehlerklassen
geprüft: fehlende Berechtigung und fremder Mandant, ungültige oder übergrosse
Eingaben, Wiederholung und Idempotenz, konkurrierende Änderungen, Ausfall eines
externen Providers sowie Manipulation von Webhooks oder Evidence. Nicht jede
Fehlerklasse ist für jedes Issue fachlich sinnvoll; in diesem Fall wird sie auf
der nächstliegenden gemeinsamen Systemgrenze geprüft.

## Geschlossene Issues: erneute Bewertung

| Issues | Prüfung über die Akzeptanzkriterien hinaus | Ergebnis |
| ------ | ------------------------------------------- | -------- |
| #1 | Vollständiger Testlauf auf PHP 8.5, frische Migrationen, isolierte Tool-Container, strikte Composer-Validierung und statische Analyse | Lokal grün; die reproduzierbare CI-Struktur passt weiterhin |
| #2 | Demo-/Produktions-Trennung, abgelaufene und wiederverwendete Einladung, erzwungener Passwortwechsel, 2FA und unzulässiger Rollenwechsel | Lokal grün; kein Wiederöffnungsgrund |
| #3 | Aktive `main`-Ruleset-Regeln, Pull Request, Review, Codeowner, gelöste Diskussionen und Required Checks sowie geschütztes Produktions-Environment erneut über GitHub geprüft | Governance ist aktiv; kein lokaler Code-Restpunkt |
| #4 | Composer- und npm-Audit sowie aktueller GitHub-Security-Workflow geprüft | **Regression entdeckt:** Der letzte Lauf auf `main` ist wegen verwundbarer Guzzle-/CommonMark-Versionen rot; der lokale Lockfile-Stand ist advisory-frei, muss aber noch veröffentlicht und in GitHub erneut ausgeführt werden |
| #5 | Immutable Build, secret-freie Konfiguration, Compose-Validierung, Non-root-Runtime und lokaler HIGH/CRITICAL-Image-Scan | Lokal grün; GitHub-Artefakt-Provenienz entsteht erst nach Veröffentlichung |
| #9–#12 | Fortsetzbares Onboarding, Profil/Medien, Suche/Matching und öffentliche Detailseiten jeweils mit Erfolg, ungültigen Eingaben, Rollen-/Tenant-Grenzen und Browser-Flows | Lokal grün; kein Wiederöffnungsgrund |
| #16 | Zentrale Capability-Matrix mit Direktzugriffen, Viewer-/Recruiter-Grenzen, eingeschränktem Support und atomarem Owner-Transfer | Lokal grün; kein Wiederöffnungsgrund |
| #18 | DE/EN-Kataloge, Fallbacks, Backend-/Frontend-Key-Konsistenz, explizite Locale-Wahl und Browserdarstellung | Lokal grün; kein Wiederöffnungsgrund |
| #21–#23 | Referral-Hold/Reversal/Deduplizierung, Moderationsgrenzen sowie Black-/Whitelist bei Registrierung, Login und laufender Session | Lokal grün; kein Wiederöffnungsgrund |
| #25 | Export, Einmaldownload, Legal Hold, Pseudonymisierung, fehlerhafter Workflow, Retry und tenant-sichere Ausführung | Lokal grün; kein Wiederöffnungsgrund |
| #36–#37 | Zammad-Synchronisation und Support-UI mit Provider-Ausfall, Replay, Reihenfolge, Manipulation, SSRF-, Größen-, Attachment- und Tenant-Grenzen | Lokal grün; reale Zammad-Abnahme bleibt Betriebs-Evidence, aber keine neu gefundene lokale Lücke |

## Querschnittsabdeckung der offenen Issues

| Issues | Happy Path | Zusätzliche Negativ-/Komplexfälle |
| ------ | ---------- | --------------------------------- |
| #6–#8, #27–#29 | Deployment, Restore, Metriken, Release-Gates, Governance und Horizon-Betrieb | Rollback, manipulierte/stale Evidence, Datenverlust, unsichere Pfade, fehlende Freigaben, Queue-/Provider-Ausfall und fail-closed Gates |
| #13–#20, #24, #26, #30–#35 | Job-, ATS-, Interview-, Organisation-, Billing-, Visa-, Notification-, Produktivitäts-, Import-, Feed-, Such- und Messaging-Flows | Rollen-/Tenant-Verletzung, Validierung, Limits, Teilfehler, Idempotenz, Optimistic Locking, gleichzeitige Änderungen, Abbruch, Wiederholung und Provider-Ausfall |
| #39–#40, #44, #48–#55, #57–#58 | Externe Nachrichten, Mail, KI, Payouts, Partnervertikalen und Chatbot | schwache Secrets, übergrosse oder manipulierte Payloads, Replay-Konflikte, Webhook-Fälschung, Opt-out/Consent-Widerruf, PII-Minimierung, Kill-Switch, Rate Limit und sichere Übergabe |
| #41–#47 | Eventmodell, Analytics, Match-Governance, weitere Locales und Ländermatrix | Backfill-Wiederaufnahme, Deduplizierung, fremde Mandanten, Datenqualitätsdrift, historische Versionen, fehlende Übersetzungen und fehlende Legal-/DPO-/Steuerfreigaben |

## Offene Issues: Verifikationsstand

| Issue | Lokaler Stand                                                                                                                                                                                                 | Noch erforderlicher Nachweis oder lokale Lücke                                                               |
| ----- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------ |
| #6    | Deployment-, Preflight-, Migrations-Lock-, Smoke- und Rollback-Automation vorhanden                                                                                                                           | Reales Staging-Environment, getrennte Secrets und dokumentierter absichtlich fehlgeschlagener Rollout fehlen |
| #7    | Verschlüsseltes Backup, isolierter lokaler Restore und Evidence-Prüfung vorhanden                                                                                                                             | Fachlich freigegebenes RPO/RTO sowie regelmäßiger Restore gegen echtes Offsite-Ziel fehlen                   |
| #8    | Metriken, Korrelation, Alerts, Zugriffsschutz und Runbooks vorhanden                                                                                                                                          | Praktischer Game Day und Nachweis kontrollierter Ausfälle in der Zielumgebung fehlen                         |
| #13   | Vollständig lokal implementiert: Vorlagen, Übersetzungen, Screening, private Medien, Löschen, Mandantentrennung, Boost-Sperre und Browserabnahme                                                              | Kein lokaler Restpunkt                                                                                       |
| #14   | Vollständig lokal implementiert: barrierearmes Drag-and-drop samt Tastaturalternative, Versionskonflikte, Details und filterbare Timeline                                                                     | Kein lokaler Restpunkt                                                                                       |
| #15   | Vollständig lokal implementiert: Vorschläge, E2EE-LiveKit-Raum, Teilnahme-Webhooks, Erinnerungen, No-show und Browser-Mockflow                                                                                | Kein lokaler Restpunkt                                                                                       |
| #17   | Vollständig lokal implementiert: Einladungslebenszyklus, Rollen, Teams, Standorte, Kontakte, Zuordnungen, Owner-Transfer, Audit und laufende Browser-Sitzungen                                                | Kein lokaler Restpunkt                                                                                       |
| #19   | Preisidentitäten, Rechnungs-/Promotion-/Tax-UI, Idempotenz, Add-ons, Up-/Downgrade, Webhook-Härtung und Runbook vollständig lokal implementiert                                                               | Dokumentierter Acceptance-Run mit echtem Stripe-Test-Clock-Konto fehlt                                       |
| #20   | Vollständig lokal implementiert: zehnstufiger Fall, Credits, Aufgaben, Fristen, Dokumentversionen, Ereignisse, Erinnerungen, Export, Rollenansichten und Browserflow                                          | Kein lokaler Restpunkt                                                                                       |
| #24   | Lokal implementiert und automatisiert getestet                                                                                                                                                                | Produktiver Kill-Switch-Nachweis erfolgt erst im Ziel-Deployment                                             |
| #26   | Matrix, Kanalpräferenzen, Push-Zustände und datensparsame Payloads implementiert und getestet                                                                                                                 | Reale Browser-Push-Abnahme in der Zielumgebung bleibt Release-Evidence                                       |
| #27   | Lokale Unit-, Integration-, Accessibility-, Security-, Build- und Lasttest-Gates vorhanden                                                                                                                    | Ein vollständig grüner GitHub-Release-Run gegen produktionsnahe Services fehlt                               |
| #28   | Versionierung, Consent-Gates, Re-Consent und unveröffentlichte Legal-Inhalte implementiert                                                                                                                    | Legal-, DPO-, AI-Act-, Vertrags- und BARMER-Freigaben fehlen                                                 |
| #29   | Horizon ist auf Laravel 13 migriert; Queues, Redaction, Zugriff und Fallback sind getestet                                                                                                                    | Deployment-/Rollback-Nachweis in Staging gehört zu #6                                                        |
| #30   | Vollständig lokal implementiert: Erinnerungen, Zeitzonen, Berechtigungen, Wiederholung, Snooze, Versand, No-op-Deduplizierung sowie mobile Tastatur-Browserabnahme                                            | Kein lokaler Restpunkt                                                                                       |
| #31   | Vollständig lokal implementiert: tenant-sichere Bulk-Aktionen, Limits, Teilfehler, Idempotenz, Abbruch, Concurrent Change und unveränderlicher Filtersnapshot                                                 | Kein lokaler Restpunkt                                                                                       |
| #32   | Vollständig lokal implementiert: CSV/XLSX-Vorschau, Mapping, Grenzen, Formelabwehr, Idempotenz, Abbruch und Teilfehler-Browserflow                                                                            | Kein lokaler Restpunkt                                                                                       |
| #33   | Vollständig lokal implementiert: versionierter Activity Feed, Datenschutzfilter, sichere Deep Links, private Broadcasts und Zwei-Browser-Deduplizierung                                                       | Kein lokaler Restpunkt                                                                                       |
| #34   | Rollen- und tenant-sichere globale Suche, Command Palette, Rollenmatrix, Tastatur-Browserflow und dokumentiertes Performancebudget implementiert                                                              | Messung gegen produktionsnahe Daten bleibt Teil des Release-Nachweises aus #27                               |
| #35   | Vollständig lokal implementiert: Realtime, Read-State, Voice, Anhänge, consent-gebundene Übersetzung, Channel-Autorisierung und Zwei-Browser-Abnahme                                                          | Kein lokaler Restpunkt                                                                                       |
| #38   | Bewusst aus dem aktuellen Produktumfang entfernt: bestätigte Interviewtermine werden ausschließlich als signierte ICS-Datei exportiert; Entscheidung und Grenzen sind in ADR 0008 dokumentiert | Eine direkte Google-/Microsoft-Synchronisation ist Zukunftsoption und benötigt vor einer Wiedereinführung ein neues fachliches und technisches Issue |
| #39   | Provider-Abstraktion, Opt-in, Verifikation, Widerruf, Kill-Switch, Webhook und Fakes implementiert und getestet                                                                                               | Produktiver Provider-, Vertrags- und Datenschutznachweis fehlt                                               |
| #40   | Mail-Webhooks, Suppression, Retry, Metriken und Provider-Fakes implementiert und getestet                                                                                                                     | Reale SPF-, DKIM- und DMARC-Prüfung der Versanddomain fehlt                                                  |
| #41   | Versioniertes, dedupliziertes und tenant-getrenntes Eventmodell samt wiederanlaufbarem Backfill, Datenqualitätszählung und dokumentierter Kontrollabfrage implementiert und getestet                          | Kein lokaler Restpunkt                                                                                       |
| #42   | Firmen-Funnel, Jobvergleich, Time-to-Hire, Filter, Export sowie mobile Accessibility-/Playwright-Abnahme implementiert und getestet                                                                           | Gemessenes Budget mit echtem 12-Monats-Datensatz fehlt                                                       |
| #43   | Rollengetrennte Plattformmetriken, Lag-/Qualitätssicht und Audit implementiert und getestet                                                                                                                   | Reconciliation gegen echte Stripe-/Produktionsdaten fehlt                                                    |
| #44   | Idempotenz, Credits, EU-/Consent-Gates, Kosten, Review und Audit implementiert und getestet                                                                                                                   | Produktiver Provider-/DPA-Nachweis bleibt extern                                                             |
| #45   | Versionen, Gewichte, historische Erklärung, Fairness-Sperren und Admin-Audit implementiert und getestet                                                                                                       | Produktive Fairness-Freigabe bleibt Governance-Evidence                                                      |
| #46   | Vollständige Offline-Entwurfskataloge für PL/RO/HR/ES/PT, technische Locale-Tests und vollständige Browser-Onboardings vorhanden                                                                              | Native Sprachreviews und Legal-Reviews fehlen                                                                |
| #47   | Versionierte Länder-/Service-Matrix und Fail-closed-Gates implementiert und getestet                                                                                                                          | Legal-, DPO- und Steuerfreigabe je Land sowie freigegebene Billing-Fixtures fehlen                           |
| #48   | Austauschbarer Payout-Provider, Intent-Saga, Webhooks, Fraud-Gate und Reconciliation implementiert und getestet                                                                                               | Acceptance-Run im gewählten Provider-Sandboxkonto fehlt                                                      |
| #49   | Gemeinsame Partnerplattform, Consent, Grants, Sperren, Webhooks und UI implementiert und getestet                                                                                                             | Produktiver Partnervertrag/DPA und Pilotnachweis fehlen                                                      |
| #50   | Sprachkurs-Vertikale auf der Partnerplattform modelliert und gegated                                                                                                                                          | Vollständiger Pilotflow mit realem Anbieter fehlt                                                            |
| #51   | Übersetzungs-/Anerkennungs-Vertikale mit Service-Details, exakter Dokumentversion, Prüfsumme, versionierten Artefakten, Partneraufgaben, minimierter Timeline und Authority-only-Entscheidungen implementiert | Pilotabnahme mit realem Anbieter fehlt                                                                       |
| #52   | Einwilligungsbasierte Versicherungs-Vertikale, Datenminimierung und Widerruf modelliert und getestet                                                                                                          | Legal-/DPO- sowie Marken-/Anbieterfreigabe fehlen                                                            |
| #53   | Wohnraum-/Reise-Vertikalen, Status, Angebote, Einwilligung und Audit auf gemeinsamer Plattform vorhanden                                                                                                      | Vollständige Angebots-/Buchungs-Abnahme mit Pilotpartner fehlt                                               |
| #54   | Steuer-/Bank-Vertikalen, Datenmanifest, Consent und verbotene Geheimnisse implementiert und getestet                                                                                                          | Produktiver Partner-/Legal-/DPO-Nachweis fehlt                                                               |
| #55   | Optionale Connectivity-Vertikale, Consent, Status und Kill-Switch implementiert und getestet                                                                                                                  | Reale Preis-/Laufzeitdaten und Anbieterfreigabe fehlen                                                       |
| #57   | Governed Support-Chatbot, Quellenpflicht, Prompt-Schutz, Retention, Metriken, Feedback, Consent und idempotenter Zammad-Handoff einschließlich Browserflow implementiert und getestet                         | Reale Zammad-/Provider-Abnahme bleibt externer Integrationsnachweis                                          |
| #58   | Payroll-Vertikale, Consent, Feldfreigaben, Rollen, Webhooks und Timeline auf gemeinsamer Plattform vorhanden                                                                                                  | Pilotpartner-Mapping sowie Legal-/Steuer-/DPO-Freigabe je Land fehlen                                        |
| #56   | Roadmap stimmt mit den oben aufgeführten Issues überein                                                                                                                                                       | Epic kann erst nach Abschluss aller abhängigen Issues geschlossen werden                                     |

## Aktuell reproduzierte technische Nachweise

- Pest: 769 Tests, 16.043 Assertions, grün.
- PHPStan: 536 Dateien, keine Fehler.
- Pint: vollständiger geänderter PHP-Bestand grün.
- Vue-Typecheck, ESLint, Prettier, i18n-Konsistenz und Produktionsbuild: grün.
- Playwright: 45 Browsertests einschließlich Rollen-, Mobile-, Realtime-, Visa-, Analytics-, Interview-, Support- und Locale-Flows, grün über wiederholte idempotente Fixture-Läufe.
- `npm audit --omit=dev`: 0 Schwachstellen.
- `composer audit --locked`: keine bekannten Advisories.
- Produktionsimage `erin-app:local-audit`: reproduzierbar gebaut, läuft als
  `www-data`; Trivy 0.70.0 meldet 0 behebbare HIGH/CRITICAL-Funde in Debian und
  Composer-Abhängigkeiten sowie keine Secret-Funde.
- Lokaler verschlüsselter Restore-Drill gegen SeaweedFS: bestanden; ausdrücklich keine Produktions-Evidence.

Die GitHub-Checkboxen und Issue-States werden erst geändert, wenn die jeweiligen
lokalen Lücken geschlossen und die explizit geforderten externen Nachweise
vorhanden sind.
