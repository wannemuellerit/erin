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

## Signierte Governance-Attestierung

Referenzen und Umgebungsvariablen allein sind keine belastbare Autorisierung:
Wer die Laufzeitkonfiguration ändern kann, könnte sonst auch eine vermeintliche
Freigabe einsetzen. Das Launch-Gate verlangt deshalb zusätzlich eine
Ed25519-signierte Attestierung, die Release-ID, Commit und den kanonischen
SHA-256-Digest der vollständigen Governance-Evidenz bindet.

Die zwei JSON-Dateien werden als read-only Secrets bereitgestellt:

- `ERIN_GOVERNANCE_ATTESTATION_PATH` verweist auf die signierte Attestierung;
- `ERIN_GOVERNANCE_TRUST_ROOT_PATH` verweist auf den separat verwalteten
  öffentlichen Trust-Root;
- `ERIN_GOVERNANCE_SECRET_ROOT` begrenzt beide Pfade auf das Secret-Verzeichnis;
- `ERIN_GOVERNANCE_ATTESTATION_MAX_LIFETIME_HOURS` begrenzt Gültigkeitsfenster
  und Alter.

Der SHA-256-Fingerprint der **exakten Bytes** des Trust-Root-Dokuments wird über
den zwingenden Docker-Buildarg `ERIN_GOVERNANCE_TRUST_ROOT_SHA256` in
`/app/.erin-governance-trust-root-sha256` eingebaut. Der Validator liest den
Fingerabdruck ausschließlich aus dieser read-only Image-Datei. Eine
Laufzeitvariable kann ihn nicht ersetzen. Vor dem Build wird er beispielsweise
so erzeugt:

```bash
export ERIN_GOVERNANCE_TRUST_ROOT_SHA256="$(
  sha256sum /sicherer/pfad/trust-root.json | awk '{print $1}'
)"
```

Das Produktions-Compose mountet die Dateien nach
`/run/secrets/erin_governance_attestation` und
`/run/secrets/erin_governance_trust_root`. Auf dem Compose-Host werden nur die
Quellpfade `ERIN_GOVERNANCE_ATTESTATION_FILE` und
`ERIN_GOVERNANCE_TRUST_ROOT_FILE` gesetzt; die zugehörigen `*_PATH`-Variablen
sind ausschließlich Containerpfade. Symlinks, Pfade außerhalb des
Secret-Verzeichnisses, ausführbare Dateien sowie gruppen- oder
weltbeschreibbare Dateien werden fail-closed abgewiesen. Private
Signaturschlüssel dürfen weder im Repository noch im Container, in `.env`,
CI-Artefakten oder dem Evidenzsystem liegen.

Attestierung, Signatur und Trust-Root enthalten keine privaten Schlüssel und
werden im Container mit `0444` ausschließlich lesbar bereitgestellt, damit der
unprivilegierte Prozess `www-data` sie sicher lesen kann. Die Quelldateien auf
dem Compose-Host müssen ebenfalls für den Container lesbar, dürfen aber für
keinen Nutzer oder keine Gruppe beschreibbar sein. Der eingebettete Fingerprint
verhindert, dass der zur Laufzeit gemountete öffentliche Trust-Root unbemerkt
ausgetauscht wird.

Die Attestierung verwendet dieses Schema:

```json
{
  "schema_version": 1,
  "type": "erin_launch_governance_attestation",
  "algorithm": "Ed25519",
  "issuer": "erin-release-authority",
  "key_id": "release-2026-01",
  "release_id": "release-2026-07-18.1",
  "commit_sha": "<40-stelliger-git-sha>",
  "evidence_sha256": "<sha256-der-kanonischen-governance-evidenz>",
  "issued_at": "2026-07-18T08:00:00Z",
  "expires_at": "2026-07-19T08:00:00Z",
  "signature": "<base64-ed25519-signatur>"
}
```

Der Trust-Root enthält ausschließlich öffentliche Schlüssel:

```json
{
  "schema_version": 1,
  "issuer": "erin-release-authority",
  "keys": [
    {
      "key_id": "release-2026-01",
      "algorithm": "Ed25519",
      "status": "active",
      "public_key": "<base64-ed25519-public-key>",
      "not_before": "2026-01-01T00:00:00Z",
      "not_after": "2027-01-01T00:00:00Z"
    }
  ]
}
```

Für eine Rotation wird der neue Schlüssel zunächst als `active` aufgenommen,
der bisherige bleibt während eines eng begrenzten Übergangs `retiring`.
Anschließend wird er auf `revoked` gesetzt oder entfernt; beide Zustände
werden für neue Attestierungen abgewiesen. Änderungen an Issuer, Trust-Root
oder Schlüsselstatus benötigen Vier-Augen-Freigabe, unveränderliche
Änderungshistorie und einen dokumentierten Rollback. Eine Signatur ist nur
gültig, wenn Attestierungs- und Schlüsselgültigkeit vollständig überlappen.
Jede `key_id` darf im Trust-Root exakt einmal vorkommen; doppelte IDs werden
unabhängig von Status oder Schlüsselmaterial vollständig abgewiesen.

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
- `ERIN_SECURITY_INDEPENDENT_REVIEW_VERIFIED=true`
- `ERIN_SECURITY_PENETRATION_TEST_VERIFIED=true`

Die letzten beiden Werte dürfen erst nach realer unabhängiger Prüfung und
Penetrationstest gesetzt werden. Eine lokale Testsuite ist dafür kein Ersatz.

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
- `ERIN_DPO_AUTHORITY_VERIFIED=true`

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
- `ERIN_LEGAL_AUTHORITY_VERIFIED=true`

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

## Adversarialer technischer Preflight

Der folgende Befehl prüft synthetisch, ob das Gate bekannte
Manipulationsversuche zuverlässig abweist:

```bash
docker compose exec -T laravel \
  php artisan erin:ops:governance-adversarial --json
```

Abgedeckt werden unter anderem Platzhalter, Eigenfreigabe,
DPO-/Legal-Rollenwiederverwendung, veraltete und zukünftige Evidenz,
Release-/Commit-Drift, schwache wiederholte Commitwerte, ein lokaler
Restore-Nachweis als vermeintliche Produktionsevidenz, verfehlte RPO-/RTO-Ziele,
Zeit-Rollback, ein synthetischer Pilot sowie fehlende unabhängige Security- und
Penetrationstestnachweise.

Vor jeder Mutation validiert der Preflight eine vollständig signierte
synthetische Baseline. Er ist nur erfolgreich, wenn diese Baseline exakt null
Fehler enthält und jeder Angriff anschließend die erwarteten zusätzlichen
Fehlercodes erzeugt. Dadurch können bereits bestehende Konfigurationsfehler
nicht fälschlich als erfolgreiche Angriffserkennung gezählt werden.

Das Ergebnis trägt `SYNTHETIC_ADVERSARIAL_PREFLIGHT`. Es beweist ausschließlich
die technische Abwehr dieser Eingaben und erteilt keine Security-, DPO-,
Legal- oder Pilotfreigabe.
