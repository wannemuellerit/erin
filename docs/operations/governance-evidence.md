# Governance-Evidenz für Security, Datenschutz und Recht

Dieses Dokument definiert die Mindestprüfung und das Evidenzformat für einen
Erin-Release. Formale Freigaben werden von real verantwortlichen Personen
außerhalb der Anwendung erteilt. Erin speichert nur nicht geheime Referenzen und
prüft deren Struktur.

## Gemeinsames Evidenzschema

| Feld | Anforderung |
|---|---|
| Release-ID | Eindeutige, versionierte Kennung; in jedem Gate identisch |
| Commit | Vollständiger 40-stelliger Git-SHA; für Release und Security identisch |
| Build | Im Image eingebauter SHA und unveränderliches Image-Tag entsprechen dem Commit |
| Identität | `Vorname Nachname <person@organisation.tld>` |
| Zeitpunkt | UTC, Format `YYYY-MM-DDTHH:MM:SSZ`, nicht zukünftig oder veraltet |
| Referenz | Credential-freie HTTPS-URL ohne Query/Fragment auf unveränderlichen Nachweis |
| Status | Exakt `approved`; Entwurf, offen oder bedingt bleibt rot |
| Rollentrennung | Vorbereitende und freigebende Person sind verschieden |

Die vorbereitende Person setzt:

- `ERIN_RELEASE_ID`
- `ERIN_RELEASE_COMMIT_SHA`
- `ERIN_RELEASE_PREPARED_BY`

Zusätzlich müssen `ERIN_BUILD_SHA` und `ERIN_APP_TAG` exakt denselben
40-stelligen Commit enthalten. Der Validator liest den Build-SHA in Produktion
aus der beim Imagebau erzeugten, nicht zur Laufzeit gemounteten Datei.

Alle Referenzen müssen für die prüfenden Rollen zugänglich, aber nicht
öffentlich sein. Kurzlebige Zugriffstoken gehören nicht in die URL oder
Umgebungsvariablen; das Evidenzsystem regelt den Zugriff selbst.

## Security-Review

### Technische Mindestchecks

- `erin:ops:security-audit --json` aus der Produktionskonfiguration ist grün.
- Pest, PHPStan, Vue-Typecheck, Lint, Build, Playwright und
  Accessibility-Prüfungen sind grün.
- `composer audit`, JavaScript-Abhängigkeitsaudit, Container-Image-Scan und
  Secret-Scan liegen als versionierte Ergebnisse vor.
- Mandanten- und Rollenmatrix wurde einschließlich Support-Impersonation
  negativ getestet.
- Upload, Quarantäne, ClamAV, MIME-/Größenlimits und signierte Downloads wurden
  missbräuchlich getestet.
- Stripe-, Zammad- und LiveKit-Eingänge wurden auf Signatur, Replay,
  Reihenfolge und Idempotenz geprüft.
- Rate-Limits, Brute-Force-Schutz, Sicherheitsheader, TLS und Proxygrenzen
  wurden gegen die reale Staging-Topologie geprüft.
- Ein unabhängiger Penetrationstest liegt vor; kritische und hohe Befunde sind
  geschlossen und nachgetestet.

### Pflichtfelder

- `ERIN_SECURITY_REVIEW_REFERENCE`
- `ERIN_SECURITY_REVIEWED_BY`
- `ERIN_SECURITY_REVIEWED_AT`
- `ERIN_SECURITY_REVIEW_RELEASE_ID`
- `ERIN_SECURITY_REVIEW_COMMIT_SHA`
- `ERIN_SECURITY_AUTOMATED_EVIDENCE_REFERENCE`
- `ERIN_SECURITY_OPEN_CRITICAL_FINDINGS=0`
- `ERIN_SECURITY_OPEN_HIGH_FINDINGS=0`

## Datenschutz-/DPO-Checkliste

Die freigebende Datenschutzrolle prüft mindestens:

- Verzeichnis der Verarbeitungstätigkeiten, Datenkategorien, Zwecke,
  Rechtsgrundlagen, Empfänger und Rollen;
- Datenminimierung für Kandidatenkarten, Entanonymisierung und
  Dokumentfreigaben;
- Einwilligungs- und Widerrufsabläufe für sensible Dokument-KI;
- Auftragsverarbeitungsverträge und TOMs für Hosting, Stripe, Zammad, OpenAI,
  LiveKit, Mail und weitere Auftragsverarbeiter;
- Drittlandtransfer, EU-Region-Pinning, Unterauftragnehmer und
  Transfer-Folgenabschätzungen;
- Lösch-, Sperr-, Aufbewahrungs- und Pseudonymisierungsplan je Datenklasse;
- vollständige Drills für Auskunft, Export, Berichtigung, Einschränkung,
  Widerspruch und Löschung;
- Datenschutz-Folgenabschätzung für Matching, Recruiting-KI, Dokumente,
  Kommunikation, Video und Trust-System;
- Transparenztexte, Kontaktwege, Incident- und
  Datenschutzverletzungsprozess;
- Schutz von Pass-, Gesundheits-, Führungszeugnis- und sonstigen besonders
  sensiblen Dokumenten.

### DPO-Pflichtfelder

- `ERIN_DPO_APPROVAL_REFERENCE`
- `ERIN_DPO_APPROVED_BY`
- `ERIN_DPO_APPROVED_AT`
- `ERIN_DPO_APPROVAL_RELEASE_ID`
- `ERIN_DPO_APPROVAL_STATUS=approved`

Die Person muss von der Release-Vorbereitung und der Legal-Freigabe verschieden
sein. Eine technisch passende Zeichenkette ersetzt keinen Nachweis ihrer
Bestellung, Rolle und tatsächlichen Prüfung.

## Legal-Checkliste

Die freigebende Rechtsrolle prüft mindestens:

- Impressum, AGB, Datenschutzinformationen und Kontaktangaben;
- B2B-Preise, Nettopreiskennzeichnung, Laufzeit, automatische Verlängerung,
  Kündigungsfrist, Upgrade, Downgrade und Proration;
- Stripe-Checkout, Rechnungsangaben, Steuerlogik, Rabattcodes und
  Zusatzkäufe;
- AGG-konforme Suche, Match-Faktoren, Begründungen und menschliche
  Letztentscheidung;
- Arbeitsvermittlung, Visa-/Relocation-Leistungsumfang, Haftung und
  regulatorische Erlaubnispflichten;
- Referral-Bedingungen, Provision, 30-Tage-Haltefrist, Steuer- und
  Auszahlungshinweise;
- Feedback, Moderation, Sperren, Trust-Kennzahlen und Kennzeichnung
  „Top Firma“;
- Partner-, BARMER-, Marken- und Logo-Aussagen samt schriftlicher Erlaubnis;
- AI-Act-Rollen, Risikomanagement, Transparenz, menschliche Aufsicht,
  Protokollierung und Beschwerdeweg;
- Urheber-, Lizenz- und Nutzungsrechte an Uploads, Bildern, Videos,
  Stellenanzeigen und KI-Ausgaben.

### Legal-Pflichtfelder

- `ERIN_LEGAL_APPROVAL_REFERENCE`
- `ERIN_LEGAL_APPROVED_BY`
- `ERIN_LEGAL_APPROVED_AT`
- `ERIN_LEGAL_APPROVAL_RELEASE_ID`
- `ERIN_LEGAL_APPROVAL_STATUS=approved`

Die Person muss von Release-Vorbereitung und DPO-Freigabe verschieden sein.

## Aufbewahrung der Evidenz

Zu jeder Referenz gehören mindestens:

- Prüfgegenstand, Release-ID und Commit;
- Name, Rolle, Organisation und Kontakt der prüfenden Person;
- Prüfdatum, Entscheidung und gegebenenfalls Auflagen;
- verwendete Checkliste und Version;
- Anhänge, Messwerte, Scannergebnisse und Nachtests;
- offene Restrisiken mit Risikoakzeptanz durch die tatsächlich befugte Rolle;
- Änderungs- und Zugriffshistorie.

Evidenz darf nicht nachträglich überschrieben werden. Eine Änderung erzeugt
eine neue Version und gegebenenfalls eine neue Freigabe.
