# Begleiteter Erin-Pilot

Der Pilot ist eine kontrollierte Produktionsphase mit kleinem Nutzerkreis. Er
beginnt erst nach technischer, Security-, Datenschutz-, Legal- und
Restore-Freigabe. Demo-Konten sind Testdaten und keine reale Pilotfreigabe.

## Rollen

Vor dem Start werden reale Personen benannt:

| Rolle | Verantwortung |
|---|---|
| Pilot-Owner | Tagesbetrieb, Kennzahlen, Kommunikation und Eskalation |
| Stellvertretung | übernimmt bei Abwesenheit und prüft Tagesprotokolle |
| Go-/No-Go-Entscheider | unabhängige Abnahme oder Stoppentscheidung |
| Security/Incident | Sicherheitsereignisse, Beweissicherung und Abschaltung |
| Datenschutz | Betroffenenrechte und Datenschutzvorfälle |
| Support | besetzter Zammad-Kanal und Nutzerkommunikation |

Pilot-Owner, Stellvertretung, Go-/No-Go-Entscheider und vorbereitende
Release-Person müssen getrennt sein.

## Pilotumfang

- maximal zwei ausdrücklich freigegebene Firmen;
- maximal zehn ausdrücklich eingewilligte Fachkräfte;
- nur Deutschland, Deutsch/Englisch und freigegebene Berufsgruppen;
- keine automatische Auswahl, Ablehnung oder Einstellung durch KI;
- Dokument-KI nur nach separat freigegebenem EU-Gate und Einwilligung;
- keine nicht freigegebenen Partner-, BARMER- oder Erfolgsversprechen;
- alle externen Integrationen ausschließlich mit produktiv freigegebenen
  Konten und Monitoring.

Die konkrete Teilnehmerliste liegt zugriffsgeschützt in der Pilotevidenz und
nicht im Repository.

## Abnahmekriterien vor Start

- Release-Gate vollständig grün und Evidence-URLs erreichbar.
- Keine offenen kritischen oder hohen Security-Befunde.
- Mandanten-, Rollen- und Support-Read-only-Matrix vollständig bestanden.
- Stripe-, Zammad-, Reverb-, Mail-, Storage-, ClamAV- und LiveKit-Smoke-Tests
  grün.
- MySQL-/MinIO-Restore innerhalb der festgelegten RPO-/RTO-Ziele.
- Datenschutz-, Legal-, Support-, Incident- und Kommunikationsabläufe
  schriftlich freigegeben.
- Monitoring, Alarmempfänger und Ruf-/Vertretungsplan aktiv.
- Rollback auf vorheriges Image und Wartungsmodus praktisch getestet.

## Messbare Pilotziele

Die Ziele werden vor Start mit konkreten Zahlen in der Evidenz festgelegt.
Mindestgrenzen:

- null bestätigte Mandanten- oder Identitätsdatenlecks;
- null unautorisierte Dokumentdownloads;
- null KI-bedingte automatische Statusänderungen;
- 100 % signaturgeprüfte und idempotent verarbeitete Webhooks;
- 100 % der Supporttickets in Erin und Zammad konsistent;
- 100 % der Interviews nur für berechtigte Teilnehmende zugänglich;
- keine offenen kritischen/hohen Incidents;
- Bewerbungs-, Interview-, Dokument- und Visa-Timelines fachlich konsistent;
- Fairness-Stichprobe dokumentiert, geschützte Merkmale ohne Gewichtung;
- Support- und Incident-Reaktionszeiten innerhalb der vorab definierten Ziele.

## Tagesablauf

1. Queue, fehlgeschlagene Jobs, 5xx, Latenz und Kapazität prüfen.
2. Stripe-/Zammad-Webhooks, Mail, Push, Reverb und LiveKit prüfen.
3. ClamAV, privaten Storage und ungewöhnliche Downloadmuster prüfen.
4. Offene Support-, Datenschutz-, Moderations- und Security-Fälle triagieren.
5. Bewerbungs-, Matching- und Interviewstichproben auf fachliche Korrektheit
   und Fairness prüfen.
6. Tageskennzahlen, Abweichungen, Entscheidungen und Owner protokollieren.
7. Stellvertretung bestätigt das Tagesprotokoll.

## Sofortige Stop-Kriterien

- bestätigter oder plausibler mandantenübergreifender Datenzugriff;
- Verlust oder öffentliche Freigabe sensibler Dokumente;
- kompromittierte Schlüssel, Tokens oder Administratorzugänge;
- KI verändert Bewerbungsstatus oder trifft autonome Personalentscheidung;
- Webhook-Duplikate erzeugen doppelte Zahlungen oder fachliche Aktionen;
- Malware gelangt aus Quarantäne in den freigegebenen Storage;
- kritischer Incident ohne wirksamen Workaround;
- DPO, Legal oder Security widerruft die Freigabe;
- Monitoring oder Auditierbarkeit ist länger als das vorab definierte
  Zeitfenster nicht verfügbar.

## Stop-/Rollback-Runbook

1. Wartungsmodus oder betroffene Feature Flags aktivieren.
2. Externe Webhookzustellung kontrolliert pausieren, ohne Ereignisse zu
   verwerfen.
3. Incident-ID, Uhrzeit, Owner und betroffene Release-ID protokollieren.
4. Beweise sichern; keine Logs, Auditdaten oder Queues unkontrolliert löschen.
5. Auf das freigegebene Vorgängerimage zurückrollen.
6. Datenmigrationen nur mit vorab geprüftem Rückwärtsplan behandeln.
7. Betroffene Nutzer und gegebenenfalls Datenschutz-/Vertragsrollen nach
   freigegebenem Kommunikationsplan informieren.
8. Idempotente Queues/Webhooks nach Freigabe kontrolliert fortsetzen.
9. Ursache beheben, nachtesten und ein neues Release-Gate durchführen.

## Go-/No-Go-Entscheidung

Nach dem vereinbarten Pilotzeitraum prüft der unabhängige Entscheider:

- Abnahmekriterien und tägliche Protokolle;
- Incidents, Datenschutzfälle, Supportqualität und offene Risiken;
- Produktkennzahlen und fachliche Fehler;
- Fairness- und KI-Qualitätsstichproben;
- Restore-, Rollback- und Monitoringnachweise;
- Rückmeldungen der teilnehmenden Firmen und Fachkräfte.

Die Entscheidung lautet `approved` oder bleibt rot. Bedingungen oder offene
Pflichtpunkte werden nicht als grün kodiert.

## Maschinenlesbare Evidenz

Pflichtfelder:

- `ERIN_PILOT_OWNER`
- `ERIN_PILOT_DEPUTY`
- `ERIN_PILOT_DECISION_BY`
- `ERIN_PILOT_DECISION_AT`
- `ERIN_PILOT_STARTED_AT`
- `ERIN_PILOT_RELEASE_ID`
- `ERIN_PILOT_PLAN_REFERENCE`
- `ERIN_PILOT_DECISION_REFERENCE`
- `ERIN_PILOT_ACCEPTANCE_REFERENCE`
- `ERIN_PILOT_ROLLBACK_REFERENCE`
- `ERIN_PILOT_STATUS=approved`
- `ERIN_PILOT_SYNTHETIC=false`
- `ERIN_PILOT_PARTICIPANT_CONSENT_VERIFIED=true`
- `ERIN_PILOT_STOP_CRITERIA_TESTED=true`
- `ERIN_PILOT_ROLLBACK_TESTED=true`

Das Setzen dieser Werte ist erst nach tatsächlichem Pilot und realer
Go-Entscheidung zulässig.

## Lokales synthetisches Pilot-Kontrollmodell

Das technische Stop- und Gate-Regelwerk kann vor dem echten Pilot rein
synthetisch mit der geplanten Obergrenze von zwei Firmen und zehn Fachkräften
modelliert werden. Der Befehl greift bewusst nicht auf Konten,
Datenbankinhalte, Integrationen oder reale Teilnehmer zu. Er führt weder
Rollback noch Wartungsmodus, Monitoring, Audit-Logging oder Benachrichtigungen
wirklich aus und ist deshalb kein Drill und kein End-to-End-Pilot:

```bash
ERIN_PILOT_DRILL_CONFIRM=SYNTHETIC_LOCAL_ONLY \
  scripts/ops/pilot-drill.sh pass
```

Ein Stop-Kriterium wird beispielsweise so geprüft:

```bash
ERIN_PILOT_DRILL_CONFIRM=SYNTHETIC_LOCAL_ONLY \
  scripts/ops/pilot-drill.sh tenant-leak
```

Das zweite Kommando muss mit einem Fehlercode enden und
`result.status=stopped` sowie `tenant_data_leak.none` als fehlgeschlagene
Stop-Prüfung ausgeben. Weitere Szenarien prüfen Dokumentleck, autonome
KI-Statusänderung, Webhook-Duplikation, Support-Desynchronisation,
Rollenkollision, Kandidaten- und Firmenüberschreitung, modelliertes
Rollback-/Wartungsmodusversagen, Monitoring- und Audit-Ausfall, versehentlich
aktive externe Benachrichtigungen, Interview-Autorisierung, kritische
Incidents, Timeline-Inkonsistenz und geschützte Matching-Faktoren.

Die lokal nicht überschreibend angelegte JSON-Evidenz und ihre SHA-256-Datei
liegen unter `storage/app/operations/evidence/pilot/`. Das macht spätere
Änderungen erkennbar, ist aber keine unveränderliche WORM-Ablage. Die Evidenz
trägt `evidence_type=synthetic_local_pilot_control_model` und
`execution_mode=rule_model_only`, ist immer als
`LOCAL_SYNTHETIC_NOT_PRODUCTION_EVIDENCE` klassifiziert, enthält
`production_gate_eligible=false` und lässt alle formalen Freigaben offen.
