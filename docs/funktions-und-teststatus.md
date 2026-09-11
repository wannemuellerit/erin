# Funktions- und Teststatus

Stand: 12. August 2026

Dieses Dokument beschreibt aus Produktsicht, was in Erin bereits nutzbar ist
und welche Abläufe vor einem echten Pilot- oder Produktivbetrieb noch mit realen
Diensten, Partnern und Nutzern abgenommen werden müssen. „Verfügbar“ bedeutet
hier: im Projekt umgesetzt und lokal automatisiert getestet. Es bedeutet nicht
automatisch, dass der Ablauf bereits unter Produktionsbedingungen freigegeben
ist.

## Bereits verfügbare Workflows

| Bereich | Was bereits funktioniert | Teststand |
| --- | --- | --- |
| Konten und Zugriffe | Registrierung, Login, Einladungen, Passwortwechsel, Zwei-Faktor-Anmeldung sowie rollenabhängige Zugriffe | Erfolgs-, Fehler- und Zugriffsgrenzen sind automatisiert getestet |
| Kandidatenprofil und Jobsuche | Profile und private Medien pflegen, Unternehmen und Stellen durchsuchen, Matching-Ergebnisse sehen und Bewerbungen verwalten | Automatisierte Funktions-, Datenschutz- und Browsertests vorhanden |
| Arbeitgeber und Recruiting | Stellen anlegen, Vorlagen und Übersetzungen verwenden, Kandidaten suchen, Pipeline per Maus oder Tastatur bedienen und mehrere Kandidaten gesammelt bearbeiten oder importieren | Happy-Path-, Teilfehler-, Abbruch-, Paralleländerungs- und Browsertests vorhanden |
| Interviews | Termine vorschlagen, Erinnerungen versenden, an einem geschützten Videoraum teilnehmen, Anwesenheit und No-show erfassen | Der Anwendungsablauf ist lokal getestet; der echte Internetbetrieb von Video und TURN ist noch abzunehmen |
| Kommunikation | Echtzeitnachrichten, Lesestatus, Anhänge, Sprachnachrichten und einwilligungsgebundene Übersetzung | Rollen-, Datenschutz-, Wiederholungs- und Zwei-Browser-Tests vorhanden |
| Benachrichtigungen | E-Mail-, In-App-, Browser-Push- und optionale externe Kanäle berücksichtigen persönliche Einstellungen, Opt-in und Widerruf | Mit Testanbietern/Fakes geprüft; echte Zustellung ist teilweise noch offen |
| Abrechnung | Tarifauswahl, Checkout, Rechnungsanzeige, Rabatte, Steuern, Zusatzsitze, Up-/Downgrade, Kündigung und Visa-Credits | Umfangreiche lokale Positiv-, Fehler-, Manipulations- und Wiederholungstests vorhanden; echte Stripe-Abnahme fehlt |
| Visa-Prozess | Zehnstufiger Fall mit Credits, Aufgaben, Fristen, Dokumenten, Erinnerungen, Rollenansichten und Export | Lokal einschließlich Browserflow getestet |
| Empfehlungen und Auszahlungen | Empfehlungen, Haltefristen, Rückabwicklung, Betrugsprüfung, Auszahlungsabsicht, Webhooks und Abgleich | Lokal mit austauschbarem Testanbieter geprüft; Sandbox-Abnahme eines echten Zahlungsanbieters fehlt |
| Support | Tickets und Anhänge, Chat mit Support, öffentliche Antworten aus Zammad sowie Übergabe vom Support-Chatbot an Zammad | Lokal einschließlich echter Container-Integration testbar; Staging-/Produktivabnahme fehlt |
| Kalender | Bestätigte Interviewtermine als signierte `.ics`-Datei herunterladen und manuell in einen kompatiblen Kalender importieren | Download, Berechtigungen und ungültige beziehungsweise unsignierte Zugriffe sind automatisiert getestet |
| Suche, Aktivitätsfeed und Auswertungen | Rollenabhängige globale Suche, sichere Verlinkungen, Recruiting-Funnel, Time-to-Hire, Exporte und Plattformkennzahlen | Funktional getestet; belastbare Messungen mit produktionsnahen Langzeitdaten fehlen |
| Partnerleistungen | Gemeinsame, einwilligungsbasierte Abläufe für Sprachkurse, Übersetzung/Anerkennung, Versicherung, Wohnen/Reise, Steuer/Bank, Connectivity und Payroll | Plattformregeln und Oberflächen sind vorhanden; reale Partner-Piloten und fachliche Freigaben fehlen |
| KI-Funktionen | Kontrollierte KI-Läufe mit Einwilligung, Kosten-/Credit-Grenzen, Review und Audit sowie ein Support-Chatbot mit Quellenpflicht und Schutzregeln | Lokal mit Testanbieter geprüft; produktiver Anbieter-, Datenschutz- und Qualitätsnachweis fehlt |
| Administration und Betrieb | Nutzer-, Firmen-, Dokument-, Support-, Billing-, Visa-, Feature-Flag-, Wartungs-, Audit- und Systemverwaltung; Queues, Statusanzeigen und Betriebsalarme | Technische Kontrollen sind automatisiert getestet; reale Betriebsübungen fehlen teilweise |

## Noch ausführlich oder mit echten Diensten zu testen

### Höchste Priorität vor einem Pilot

1. **Stripe:** Den vollständigen Tariflebenszyklus in einem echten
   Stripe-Testkonto mit Test Clocks durchführen: Checkout, Rabatt und Steuer,
   Upgrade, Downgrade, Zusatzsitze, Kündigungsfristen, fehlgeschlagene und später
   bezahlte Rechnungen, Rückerstattung von Visa-Credits, Webhook-Wiederholungen
   und abschließender Datenabgleich. Dieser Ablauf ist lokal sehr ausführlich
   getestet, aber noch nicht als echter Stripe-Acceptance-Run dokumentiert.
2. **Deployment und Rollback:** Einen Release in einer produktionsnahen
   Stagingumgebung ausrollen, einen Fehler gezielt provozieren und den
   vollständigen Rollback samt getrennten Secrets und Protokoll nachweisen.
3. **Backup und Wiederherstellung:** Datenbank und Objektspeicher aus einem
   echten verschlüsselten Offsite-Backup gemeinsam wiederherstellen. Die
   zulässigen Datenverluste und Wiederherstellungszeiten müssen fachlich
   freigegeben und real gemessen werden.
4. **Video-Interviews:** Einen Zwei-Browser-Test aus unterschiedlichen Netzen
   durchführen, darunter ein restriktives Firmennetz. Kamera, Mikrofon,
   Bildschirmfreigabe, Gerätewechsel, Verbindungsabbruch und TURN/TLS-Fallback
   müssen mit der vorgesehenen deutschen LiveKit-Infrastruktur funktionieren.
5. **Security und Betrieb:** Einen Game Day mit kontrollierten Ausfällen sowie
   externe Sicherheitsprüfungen durchführen. Alarmierung, Queue-Rückstau,
   Wartungsmodus, Wiederanlauf und Eskalation müssen im Zielsystem belegt sein.

### Externe Integrationen und Zustellung

- **Zammad:** Den kompletten Ticket-, Antwort-, Anhang-, Ausfall- und
  Wiederanlauf-Workflow über die öffentliche HTTPS-Integration in Staging
  abnehmen; anschließend Backup und Restore von Zammad prüfen.
- **E-Mail:** SPF, DKIM, DMARC, Bounce, Complaint, Sperre und erneute Freigabe
  mit der echten Versanddomain und dem ausgewählten Anbieter testen.
- **Browser-Push und externe Nachrichtenkanäle:** Reale Geräte und Browser,
  Opt-in/Opt-out, ungültige Abos, Provider-Ausfälle und Kill-Switch in der
  Zielumgebung prüfen.
- **Auszahlungen:** Normalfall, Ablehnung, Betrugsprüfung, Timeout, doppelte und
  verspätete Webhooks sowie Reconciliation im Sandboxkonto des ausgewählten
  Payout-Anbieters abnehmen.
- **KI:** Den vorgesehenen EU-Endpunkt, Datenverarbeitung, Kostenlimits,
  Qualitätsreview, Provider-Ausfall und Übergabe an Menschen mit dem realen
  Vertragspartner prüfen.

### Produkt-, Daten- und Partnerabnahme

- Suche und Auswertungen mit einem produktionsnahen Datenbestand über zwölf
  Monate messen; insbesondere Antwortzeiten, Datenqualität und Abgleich mit
  echten Stripe-/Produktdaten dokumentieren.
- Übersetzungen für Polnisch, Rumänisch, Kroatisch, Spanisch und Portugiesisch
  durch Muttersprachler sowie rechtlich prüfen lassen.
- Länder-, Billing-, Datenschutz-, Steuer- und Fairnessregeln je Zielmarkt
  fachlich freigeben.
- Alle Partnerangebote mit mindestens einem echten Partner als vollständigen
  Pilotablauf testen, einschließlich Einwilligung, Widerruf, Dokumentfreigaben,
  Statusänderungen, Fehlerfällen und Support.
- Einen begleiteten Pilot mit echten Rollen, Messwerten, Stop-Kriterien und
  unabhängiger Go-/No-Go-Entscheidung durchführen.

## Gesamtbewertung

Die Kernanwendung und die meisten fachlichen Abläufe sind verfügbar und breit
automatisiert getestet. Noch nicht vollständig freigegeben sind vor allem
Abläufe, deren Aussagekraft von echten Drittanbietern, produktionsnaher
Infrastruktur, realistischen Daten, rechtlichen Freigaben oder einem
begleiteten Pilot abhängt.

Deshalb gilt aktuell: **lokal funktionsfähig und technisch gut abgesichert,
aber noch nicht vollständig produktionsabgenommen**. Offene GitHub-Issues mit
solchen Nachweisen sollten erst geschlossen werden, wenn das jeweilige
Abnahmeprotokoll tatsächlich vorliegt.

## Grundlage dieser Übersicht

- `docs/operations/open-issue-acceptance-audit.md`
- `docs/operations/stripe-staging.md`
- `docs/operations/zammad-staging.md`
- `docs/operations/livekit-self-hosting.md`
- `docs/operations/production-readiness.md`
- `docs/operations/launch-gates.md`
- vorhandene Feature-, Unit- und Browsertests im Projekt
