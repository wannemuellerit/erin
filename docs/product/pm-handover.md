# Faden: Produkt- und Projektübergabe für Product Management

Stand: 2. September 2026

Repository: `wannemuellerit/erin`

Produktname: **Faden**
Interner Projekt- und technischer Präfix: **Erin** / `ERIN_*`

## 1. Kurzfassung

Faden ist ein Recruiting Operating System für die Gewinnung internationaler
Fachkräfte durch Unternehmen in Deutschland. Das Produkt begleitet den gesamten
Weg vom Kandidatenprofil und der Stellenausschreibung über Suche, Matching,
Bewerbung, Kommunikation und Interview bis zu Visa-/Relocation-Schritten.

Für Fachkräfte ist die Nutzung kostenlos. Unternehmen nutzen zeitlich definierte
B2B-Pakete mit Kontingenten für aktive Stellen, Teammitglieder, Recruiting-KI,
Job-Boosts und Visa-Pakete. Abrechnung und Freischaltung sind über Stripe
vorgesehen.

Der Codebestand ist bereits eine breite, sicherheitsorientierte Plattform und
kein bloßer Prototyp. Gleichzeitig ist Faden noch nicht als frei freigegebenes
Produkt zu behandeln: Produktionsbetrieb, externe Integrationen, Rechtstexte,
Datenschutzprüfung, Penetrationstest, Restore-Nachweis und begleiteter Pilot sind
bewusst als harte Launch-Gates modelliert.

## 2. Produktpositionierung

### Produktversprechen

Faden verbindet Unternehmen in Deutschland mit qualifizierten Menschen aus
Europa und der Welt. Der Schwerpunkt liegt nicht nur auf dem Finden von
Kandidaten, sondern auf einem nachvollziehbaren, durchgängigen Recruiting- und
Onboardingprozess.

Die drei zentralen Produktversprechen sind:

1. **Erklärbares Matching:** Ein Match soll nicht nur einen Score liefern,
   sondern die ausschlaggebenden Faktoren transparent machen.
2. **Geschützter Dokumentenprozess:** Kandidaten behalten Kontrolle über
   sensible Unterlagen; Dateien werden privat gespeichert, geprüft und gezielt
   freigegeben.
3. **Durchgängiger Prozess:** Bewerbung, Kommunikation, Interview, Visa und
   Relocation sollen nicht auf voneinander getrennte Werkzeuge verteilt sein.

### Aktuell fokussierte Berufsgruppen

- Elektriker
- Elektroniker
- LKW-Fahrer
- Pflegefachkräfte
- Hilfskräfte

Die initiale Plattform ist auf Deutsch und Englisch ausgelegt. Im Fachkatalog
sind zusätzlich Deutsch, Englisch, Polnisch, Rumänisch, Kroatisch, Spanisch und
Portugiesisch als Sprachqualifikationen hinterlegt.

## 3. Zielgruppen und Rollen

### Fachkräfte

Fachkräfte erstellen ein Profil mit Beruf, Erfahrung, Ausbildung, Fähigkeiten,
Sprachen, Verfügbarkeit, Standort, Gehaltsvorstellung und Visa-Bedarf. Sie können
Dokumente verwalten, Stellen und Unternehmen entdecken, Bewerbungen einreichen,
Einladungen beantworten, kommunizieren und Interviews koordinieren.

Veröffentlichte Kandidatenprofile sind für die Suche datenminimiert. Direkte
Identitäts- und Dokumentzugriffe werden nicht pauschal freigegeben.

### Unternehmen

Unternehmen erhalten ein mandantenfähiges Recruiting-Portal. Innerhalb eines
Unternehmens existieren die Rollen:

| Rolle | Wesentliche Befugnisse |
|---|---|
| Owner | Vollzugriff inklusive Abrechnung und Eigentumsübertragung |
| Admin | Recruiting, Firmenprofil und Teamverwaltung, keine Abrechnung |
| Recruiter | Operatives Recruiting, keine Team- oder Abrechnungsverwaltung |
| Viewer | Ausschließlich lesender Zugriff |

Unternehmen können mehrere Standorte und Teams verwalten. Der aktive
Firmenkontext wird serverseitig auf eine angenommene Mitgliedschaft begrenzt.

### Plattformbetrieb

| Rolle | Zweck |
|---|---|
| Superadmin | Plattform, Nutzer, Firmen, Abrechnung, Dokumentprüfung, Governance und Einstellungen |
| Support | Supportbearbeitung und streng schreibgeschützte Nutzeransicht |

Administrative Zugriffe erfordern Zwei-Faktor-Authentifizierung. Support-
Impersonation ist technisch schreibgeschützt und wird auditiert.

## 4. Funktionsbestand

### Öffentlicher Bereich

- Landingpage und Produktpositionierung
- öffentliche Preisübersicht
- Kontaktseite
- deutsche und englische Oberfläche
- Registrierung, E-Mail-Verifikation und Login
- sichere Ersteinrichtung des ersten Superadmins
- Routen für Impressum, Datenschutz und AGB mit expliziter
  Veröffentlichungsfreigabe
- Referral-Links und Attribution

### Kandidatenbereich

- geführtes Onboarding
- umfangreiches Kandidatenprofil
- Profilvollständigkeit und Veröffentlichungsschwelle
- Profilfoto mit privater Speicherung und Malware-Prüfung
- Dokumente hochladen, bearbeiten, ersetzen und löschen
- Dokumentversionen, Ablaufdaten und Prüfstatus
- zeitlich begrenzte Dokumentfreigaben für konkrete Bewerbungen
- Stellen- und Unternehmenssuche
- gespeicherte Suchkriterien
- Bewerbung und Rückzug einer Bewerbung
- Reaktion auf Unternehmenseinladungen
- Nachrichten, Anhänge und Benachrichtigungen
- Interviewvorschläge, Gegenangebote, Verfügbarkeit und Kalenderdateien
- KI-Studio mit nachvollziehbarer Einwilligung und Widerruf
- Empfehlungsprogramm

### Arbeitgeberbereich

- Firmen-Onboarding, Profil, Standorte und Medien
- Paketwahl und Stripe-Abrechnung
- Team-Einladungen und rollenbasierte Rechte
- Kandidatensuche mit Filtern und datenschutzreduziertem Suchindex
- Talentlisten und gespeicherte Suchen
- Einzel- und Masseneinladungen
- CSV-Kandidatenimport mit Feldzuordnung
- detaillierte Stellenanzeigen mit Aufgaben, Anforderungen, Vorteilen,
  Arbeitszeit, Vergütung, Skills, Sprachen und Screening-Fragen
- Entwurf, Veröffentlichung, Pause, Besetzung und Archivierung von Stellen
- Duplizieren von Stellen als neuer Entwurf
- Job-Medien, Bewerbungsfristen, Startdatum, Anzahl offener Stellen und
  Ansprechpartner
- Bewerberpipeline und interne Kandidatenbewertung
- Recruiting-Erinnerungen und Produktivitätsansicht
- Recruiting-Analytics
- Nachrichten und Interviews
- Visa-Fälle und Prozessschritte
- Job-Boosts sowie paketabhängige Kontingente

### Plattformadministration

- Nutzer- und Firmenverwaltung
- administratives Anlegen vorbereiteter Kandidatenkonten
- Dokumentprüfung und Malware-Status
- Paket-, Preis- und Stripe-Konfigurationsübersicht
- Supporttickets mit Zammad-Synchronisierung
- Visa-Übersicht
- Referral- und Provisionsverwaltung
- Audit-Logs, Login-Historien, Sicherheitswarnungen und Export
- DSGVO-Anfragen mit Export-, Sperr- und Löschabläufen
- Moderation, Feedback und Trust-Kennzahlen
- Feature Flags, Plattformtexte, Theme und E-Mail-Vorlagen
- Rollen- und Capability-Verwaltung
- sichere, schreibgeschützte Support-Impersonation

## 5. Geschäftsmodell und Pakete

Die im aktuellen Katalog hinterlegten Preise sind Nettopreise für das jeweilige
Paket und sollten vor dem Marktstart fachlich und rechtlich nochmals bestätigt
werden.

| Paket | Preis | Laufzeit | Aktive Jobs | Seats | KI-Credits/Monat | Job-Boosts | Visa-Pakete |
|---|---:|---:|---:|---:|---:|---:|---:|
| Basic | 2.999 € | 2 Monate | 1 | 1 | 0 | 0 | 0 |
| Business | 3.499 € | 4 Monate | 3 | 5 | 250 | 1 | 5 |
| Premium | 4.999 € | 6 Monate | 5 | 15 | 750 | 3 | 15 |
| Enterprise | individuell | individuell | unbegrenzt | unbegrenzt | unbegrenzt | unbegrenzt | unbegrenzt |

Zusätzliche Recruiter-Seats und Visa-Credits können als Einmalkäufe vorgesehen
werden. Paketwechsel, Kündigungen und Zusatzkäufe werden erst nach verifizierten
Stripe-Webhooks wirksam; Browser-Weiterleitungen allein schalten keine Leistung
frei.

## 6. Fachliche Kernabläufe

### Kandidat bis Bewerbung

1. Konto registrieren und E-Mail bestätigen.
2. Kandidaten-Onboarding abschließen.
3. Profil, Qualifikationen und Dokumente vervollständigen.
4. Profil freiwillig für die Suche veröffentlichen.
5. Stelle finden oder Einladung eines Unternehmens erhalten.
6. Bewerbung absenden und Screening-Fragen beantworten.
7. Dokumente bei Bedarf gezielt für die konkrete Bewerbung freigeben.
8. Über Nachrichten und Interviewplanung mit dem Unternehmen fortfahren.

### Unternehmen bis Einstellung

1. Firmenkonto registrieren, E-Mail bestätigen und Onboarding abschließen.
2. Firmen- und Rechnungsdaten pflegen und Paket auswählen.
3. Freischaltung nach bestätigtem Stripe-Ereignis erhalten.
4. Team und Rollen einrichten.
5. Stelle als Entwurf anlegen und Vollständigkeitsprüfung bestehen.
6. Stelle veröffentlichen oder Kandidaten direkt suchen und einladen.
7. Bewerbungen in der Pipeline bearbeiten.
8. Kommunikation, Interview und gegebenenfalls Visa-Schritte koordinieren.
9. Einstellung und Referral-Auswirkung nachvollziehbar dokumentieren.

### Support

Supporttickets werden zuerst lokal in Faden gespeichert und anschließend über
eine dauerhafte Outbox mit Zammad synchronisiert. Öffentliche Antworten werden
in beide Richtungen abgeglichen; interne Zammad-Notizen bleiben intern.

## 7. Datenschutz- und Sicherheitsprinzipien

Faden verarbeitet umfangreiche und teilweise besonders schutzbedürftige
Recruitingdaten. Datenschutz und Security sind deshalb Teil des Produktmodells
und keine reine Betriebsaufgabe.

Wesentliche technische Prinzipien:

- strikte Mandantentrennung und serverseitige Capability-Prüfung
- private Dokument- und Medienspeicherung
- Malware-Prüfung mit ClamAV vor Freigaben
- signierte, zeitlich begrenzte Downloadlinks
- dokumentbezogene Freigaben statt globalem Arbeitgeberzugriff
- Widerruf und automatischer Ablauf von Dokumentfreigaben
- verschlüsselte Sessions und Admin-2FA
- Passkeys und Rate Limits für Authentifizierungswege
- Auditierung sensibler Aktionen
- DSGVO-Export, Pseudonymisierung, Legal Hold und Löschworkflow
- keine automatische Auswahl, Ablehnung oder Einstellung durch KI
- sensible Dokument-KI standardmäßig deaktiviert, bis EU-Datenkontrollen und
  Einwilligung freigegeben sind
- datenschutzreduzierter Meilisearch-Index ohne direkte Identitätsdaten

Die produktive Verantwortlichkeit zwischen Plattformbetreiber und
Arbeitgebern, Auftragsverarbeitungsverträge, Löschfristen und eine
Datenschutz-Folgenabschätzung müssen außerhalb des Codes verbindlich festgelegt
und freigegeben werden.

## 8. Technik und Betrieb

### Anwendung

- Backend: PHP 8.4, Laravel 13, Fortify, Cashier, Scout, Reverb
- Frontend: Vue 3, Inertia 3, TypeScript, Tailwind CSS 4
- Datenbank: MySQL 8.4
- Cache, Sessions und Queues: Redis
- Suche: Meilisearch
- private Dateien: S3-kompatibler Storage beziehungsweise MinIO
- Malware-Prüfung: ClamAV
- Echtzeit: Reverb/Pusher-Protokoll
- Browser-Push: VAPID
- Tests: Pest 4, PHPStan Level 7, Vue Typecheck, ESLint, Prettier und Playwright

### Externe Integrationen

| Integration | Zweck | Produktionsstatus |
|---|---|---|
| Stripe | Pakete, Checkout, Abonnements und Zusatzkäufe | Konfigurations- und Stagingprüfung erforderlich |
| Zammad | Supporttickets und Antworten | Produktionskonto und Smoke-Test erforderlich |
| OpenAI | Recruiting- und optionale Dokument-KI | nur nach Datenschutzfreigabe aktivieren |
| LiveKit | Video-Interviews | EU-Region, WSS und E2EE vor Produktion nachweisen |
| Mail-Anbieter | Transaktions- und Benachrichtigungsmails | Anbieter und AV-Vertrag festlegen |

### Deployment

Das Produktionssetup verwendet getrennte, unveränderliche PHP-FPM- und
Nginx-Images. Releases werden an einen vollständigen Git-Commit gebunden.
Readiness, Sicherheitsprüfung, externe Freigabeevidenz, Migration, Smoke-Test
und Rollback sind als eigene Gates vorgesehen.

Backups müssen MySQL und privaten Objektspeicher gemeinsam abdecken,
verschlüsselt außerhalb der primären Umgebung liegen und durch einen echten
Restore-Drill nachgewiesen werden. Redis und Meilisearch sind nicht die
maßgebliche Datenquelle.

## 9. Qualitätssicherung

Die CI umfasst:

- PHP-Codeformatierung
- PHPStan Level 7
- Produktionsbuild des Frontends
- Pest-Feature- und Domaintests
- ESLint und Prettier
- Prüfung der deutschen und englischen Übersetzungskataloge
- Vue-/TypeScript-Prüfung
- Playwright-Ende-zu-Ende-Tests
- Build der Produktionsimages
- separate Security-, Release-, Deploy- und Backup-Workflows

Die Testabdeckung konzentriert sich neben Produktabläufen besonders auf
Mandantentrennung, Rollenrechte, private Dateien, Webhook-Manipulation,
Idempotenz, Governance, Support-Synchronisierung und Wiederanlauf nach Fehlern.

Validierungsstand vom 2. September 2026:

- 657 Pest-Tests mit 6.629 Assertions erfolgreich
- PHPStan Level 7 ohne Fehler
- ESLint, Prettier, Übersetzungs- und Vue-/TypeScript-Prüfung erfolgreich
- Produktionsbuild erfolgreich
- 30 Playwright-Ende-zu-Ende-Tests erfolgreich

## 10. Aktueller Lieferstand

### Im Code vorhanden

- vollständige Rollen- und Mandantenbasis
- Kandidaten-, Arbeitgeber- und Adminportale
- Recruiting- und Kommunikationskern
- Stripe-, Zammad-, OpenAI- und LiveKit-Anbindungspunkte
- produktionsnaher Docker- und Deploymentpfad
- umfangreiche automatisierte Qualitäts- und Sicherheitsprüfungen
- formalisierte Launch-, Governance-, Restore- und Pilot-Gates

### Mit dem aktuellen Feature-Stand ergänzt

- vollständiger Kandidatendokument-Lebenszyklus inklusive Ersetzen,
  Versionierung und Widerruf aktiver Freigaben
- erweiterte, strukturierte Stellenanzeigen und Screening-Fragen
- Veröffentlichungs-Readiness für Stellen
- sichere Stellen-Duplizierung ohne Bewerbungen und Medien zu kopieren
- administratives Anlegen von Kandidaten mit Pflicht zum Passwortwechsel
- neue Featuretests für diese Abläufe

### Noch nicht als produktiv freigegeben

- tatsächliche Produktionsumgebung und produktive Providerkonfiguration
- anwaltlich freigegebene AGB, Datenschutzerklärung und finales Impressum
- bestätigte Register- und Steuerdaten der Betreiber-UG
- Datenschutz-Folgenabschätzung und AV-Verträge
- unabhängiger Security-Review und Penetrationstest
- echter externer Backup-/Restore-Nachweis mit festgelegtem RPO und RTO
- kalibriertes Monitoring und besetzte Incident-/Supportbereitschaft
- dokumentierter Pilot mit realen, eingewilligten Teilnehmern
- abschließende Go-/No-Go-Entscheidung

## 11. Bekannte Produkt- und Projektrisiken

| Risiko | Bedeutung für PM | Nächste Entscheidung |
|---|---|---|
| Breiter Funktionsumfang vor Pilot | Fokus und Abnahme können verwässern | Pilot-Must-haves verbindlich festlegen |
| Sensible Kandidatendaten | Hohe Datenschutz- und Vertrauensanforderungen | Rollenmodell und DSFA mit Datenschutzverantwortlichen abnehmen |
| KI im Recruiting | Fairness-, Transparenz- und Regulierungsrisiko | zulässige Use Cases und menschliche Letztentscheidung definieren |
| Visa-/Relocation-Versprechen | Gefahr eines unklaren oder erlaubnispflichtigen Leistungsbilds | konkreten Serviceumfang rechtlich prüfen |
| Paket- und Laufzeitmodell | Preise und Kontingente sind technisch konkret, aber noch freigabebedürftig | Zielkundengespräche und Vertragslogik abgleichen |
| Mehrere externe Provider | Launch hängt von Verträgen, Konfiguration und Monitoring ab | Providerliste und Verantwortliche finalisieren |
| Referral-Provisionen | Steuer-, Vertrags- und Missbrauchsfragen | Teilnahmebedingungen und Auszahlungsvorgaben freigeben |
| Kein freier Launch ohne Evidenz | Technische Fertigstellung allein reicht nicht | Owner und Termine für jedes Launch-Gate benennen |

## 12. Empfohlener Pilot

Der vorhandene Pilotplan begrenzt den Start auf maximal zwei freigegebene
Unternehmen und zehn eingewilligte Fachkräfte. Er startet nur nach technischer,
Security-, Datenschutz-, Legal- und Restore-Freigabe.

Für den Pilot sollten mindestens folgende Kennzahlen geführt werden:

- Profilvollständigkeit und Zeit bis zur Veröffentlichung
- Zeit von Stellenentwurf bis Veröffentlichung
- relevante Matches je Stelle
- Einladungs-, Antwort- und Bewerbungsquote
- Zeit je Bewerbungsphase
- Interviewannahme und No-Show-Rate
- Dokumentprüfungsdauer und fehlgeschlagene Uploads
- Supportaufkommen und Reaktionszeit
- technische Fehler, Webhook-Retries und Synchronisationsabweichungen
- qualitative Fairness-Stichproben der Match-Begründungen

Sofortige Stop-Kriterien sind insbesondere mandantenübergreifende Datenzugriffe,
unautorisierte Dokumentdownloads, kompromittierte Zugänge, autonome
KI-Statusänderungen, doppelte Zahlungen, Malware-Freigaben oder der Widerruf
einer formalen Freigabe.

## 13. Prioritäten für das Product Management

### Erste Woche

1. Produktname, Betreiber, Zielkundensegment und Pilotumfang bestätigen.
2. Die drei Hauptreisen Kandidat, Arbeitgeber und Support selbst im Demosystem
   vollständig durchlaufen.
3. Paketpreise, Laufzeiten, Kontingente und versprochene Visa-Leistungen mit
   Vertrieb und Geschäftsführung abgleichen.
4. Für jedes Launch-Gate eine entscheidungsbefugte Person und einen Termin
   benennen.
5. Einen einzigen priorisierten Pilot-Backlog mit Abnahmekriterien erstellen.

### Vor dem Pilot

1. Rechtstexte und Datenschutzunterlagen freigeben.
2. Produktionsprovider, Verträge, Regionen und Unterauftragnehmer festlegen.
3. Stripe, Zammad, Mail, Storage, ClamAV, Realtime und Video Ende-zu-Ende
   abnehmen.
4. Restore, Monitoring, Incidentablauf und Rollback praktisch testen.
5. Pilotunternehmen, Fachkräfte, Supportkanal und Go-/No-Go-Prozess festlegen.

## 14. Wichtige Dokumente

- `README.md`: lokaler Start, Architektur und Integrationen
- `docs/operations/production-readiness.md`: Produktionsbetrieb und Readiness
- `docs/operations/launch-gates.md`: formale Freigabebedingungen
- `docs/operations/governance-evidence.md`: Security-, Datenschutz- und
  Legal-Checklisten
- `docs/operations/pilot-runbook.md`: Pilotumfang und Stop-Kriterien
- `docs/operations/deployment-runbook.md`: Deployment und Rollback
- `docs/operations/backup-restore-drill.md`: Backup- und Restore-Nachweis
- `docs/operations/stripe-staging.md`: Stripe-Abnahme
- `docs/operations/zammad-staging.md`: Zammad-Betrieb und Synchronisierung
- `docs/security/capability-matrix.md`: Rollen- und Rechtemodell
- `docs/search/candidate-index.md`: Datenschutzgrenze der Kandidatensuche
- `docs/privacy/referrals.md`: Referral-Attribution und Datenschutz

## 15. Begriffe

| Begriff | Bedeutung |
|---|---|
| Erin | interner Repository-, Command- und Konfigurationsname |
| Faden | öffentlicher Produktname |
| Capability | einzelne serverseitig geprüfte Berechtigung |
| Company Context | aktuell ausgewähltes Unternehmen eines Nutzers |
| Visa-Credit | Paketkontingent für einen Visa-Prozess |
| Job-Boost | zeitlich begrenzte Hervorhebung einer Stelle |
| Legal Hold | Sperre einer Löschung wegen rechtlicher Aufbewahrung |
| Launch-Gate | zwingende technische oder externe Freigabebedingung |
| Governance-Evidenz | releasegebundener Nachweis einer formalen Prüfung |

## 16. PM-Leitsatz

Faden ist technisch weit, aber der nächste Erfolg ist nicht „noch mehr
Funktionen“. Der nächste Erfolg ist ein klar begrenzter, rechtlich und operativ
freigegebener Pilot, in dem die Kernreise vom qualifizierten Profil bis zum
nachvollziehbaren Recruiting-Ergebnis messbar funktioniert.
