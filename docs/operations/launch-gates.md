# Security-, Datenschutz-, Legal- und Pilot-Gates

Ein öffentlicher Release ist nur zulässig, wenn die technische Baseline und
alle extern verantworteten Gates erfüllt sind. Erin kann Nachweise prüfen, aber
weder eine Datenschutzbeauftragte, eine Rechtsberatung noch eine reale
Pilotentscheidung ersetzen.

## Freigabeablauf

1. Ein Release-Kandidat erhält eine eindeutige `ERIN_RELEASE_ID` und den
   vollständigen 40-stelligen Git-Commit in `ERIN_RELEASE_COMMIT_SHA`.
2. Derselbe Commit wird als `ERIN_BUILD_SHA` in beide Images eingebaut und als
   unveränderliches `ERIN_APP_TAG` verwendet.
3. Die vorbereitende Person wird als
   `Vorname Nachname <person@organisation.tld>` erfasst.
4. Technische Prüfungen werden ausgeführt und ihre unveränderliche
   HTTPS-Evidenz referenziert.
5. Security, Datenschutz, Recht, Restore und Pilot werden von den jeweils
   verantwortlichen Personen gegen genau diese Release-ID geprüft.
6. Erst anschließend darf das strikte Release-Gate grün werden.

```bash
php artisan erin:ops:security-audit --json
php artisan erin:ops:readiness --strict --probe --json
```

Der gebündelte Lauf ist:

```bash
bash scripts/ops/release-gate.sh
```

## Technische Sicherheitsprüfung

`erin:ops:security-audit` prüft aktuell:

- Produktionsmodus, HTTPS, ausgeschalteten Debug-/Demo-Modus und `APP_KEY`;
- Übereinstimmung von eingebautem Build-SHA, Image-Tag und Release-Evidenz;
- Secure-, HttpOnly-, SameSite- und verschlüsselte Sessions;
- explizite Proxy-CIDRs und von Nginx überschriebene Forwarded-Header;
- Redis für Queue, Cache und Sessions;
- privaten, fail-closed Storage mit bucket-begrenztem MinIO-App-Nutzer;
- 2FA-Schutz des Adminbereichs und signierte sensible Downloads;
- getrennte Rate-Limiter für Login, 2FA und Passkeys;
- Authentifizierung, Staff-2FA und Autorisierung für Telescope;
- OpenAI- und Dokument-KI-Datenkontrollen;
- LiveKit mit WSS, EU-Pinning, E2EE und kurzlebigen Tokens;
- Reverb mit expliziten Origins und terminierendem Rate-Limit.

Die JSON-Ausgabe muss als unveränderliches CI-Artefakt oder in einem
zugriffsgeschützten Evidenzsystem gespeichert werden. Zusätzlich bleiben
Abhängigkeits-, Image-, Secret- und DAST-Scans sowie ein externer
Penetrationstest erforderlich. Kritische oder hohe offene Befunde blockieren
das maschinenlesbare Gate.

## Formale Freigaben

Die vollständigen Checklisten und das Evidenzschema stehen in
[governance-evidence.md](governance-evidence.md). Insbesondere gilt:

- Eine nichtleere Referenz allein reicht nicht.
- Identitäten müssen aus Vor- und Nachname sowie gültiger E-Mail-Adresse
  bestehen.
- Platzhalter, Beispiel-Domains, lokale URLs, URL-Credentials, Query-Parameter
  und Fragmente werden abgelehnt.
- Referenzen müssen credential-freie HTTPS-URLs auf unveränderliche Evidenz
  sein.
- Zeitpunkte verwenden `YYYY-MM-DDTHH:MM:SSZ`, dürfen nicht in der Zukunft
  liegen und unterliegen einer konfigurierten Höchstdauer.
- Jede Freigabe ist an dieselbe Release-ID gebunden; der Security-Review
  zusätzlich an exakt denselben Commit.
- Die vorbereitende Person darf keine eigene Security-, DPO-, Legal-, Restore-
  oder Pilotfreigabe erteilen.
- DPO- und Legal-Rolle müssen getrennt sein. Pilot-Owner, Stellvertretung und
  Go-/No-Go-Entscheider müssen ebenfalls verschieden sein.

Die Syntaxprüfung kann nicht beweisen, dass eine Person tatsächlich existiert
oder die angegebene Rolle besitzt. Diese Identität und Befugnis ist Bestandteil
der außerhalb von Erin aufbewahrten Freigabeevidenz.

## Backup-/Restore-Gate

Eine verständliche Erklärung von RPO/RTO, Verschlüsselung, MySQL-/MinIO-Konsistenz,
Drillablauf und Evidenz steht in
[backup-restore-drill.md](backup-restore-drill.md).

Das Gate wird nur grün, wenn Datenbank **und** Objektstorage in einer isolierten
Umgebung erfolgreich wiederhergestellt wurden, die Backups verschlüsselt waren
und die gemessenen RPO-/RTO-Werte ihre Ziele nicht überschreiten.

## Pilot-Gate

Der ausführbare Pilotplan einschließlich Owner, Stellvertretung,
Abnahmekriterien, täglichem Ablauf, Stop-/Rollback-Kriterien und
Go-/No-Go-Protokoll steht in [pilot-runbook.md](pilot-runbook.md).

Ein benannter Owner allein ist keine Pilotfreigabe. Erforderlich sind getrennte
Rollen, ein freigegebener Plan, referenzierte Messwerte und eine dokumentierte
Go-Entscheidung.

## Bedeutung eines grünen Commands

Exit-Code `0` bedeutet:

- Die aktuell prüfbaren technischen Bedingungen waren erfolgreich.
- Das Evidenzschema ist vollständig, aktuell, releasegebunden und verletzt
  keine maschinell erkennbare Eigenfreigabe.

Exit-Code `0` bedeutet ausdrücklich **nicht**, dass Erin selbst Rechtsberatung,
Datenschutzprüfung, Penetrationstest oder Pilotentscheidung durchgeführt hat.
