# Deployment-, Migrations- und Rollback-Runbook

## Freigabekette

Ein Release wird ausschließlich als vollständiger 40-stelliger Commit-SHA
gebaut. Der Release-Workflow erzeugt App- und Nginx-Images, SBOM,
Build-Provenienz und keyless Cosign-Signaturen; der Deployment-Workflow
verifiziert die GitHub-Attestierung vor jedem Zugriff auf Staging oder
Produktion.

Staging und Produktion sind getrennte GitHub Environments mit getrennten
Runnern, Secrets und Freigaberegeln. Das Production-Environment benötigt eine
manuelle Freigabe durch eine benannte betriebliche Verantwortliche; Pull
Requests erhalten weder Environment-Secrets noch Runnerzugriff.

## Ablauf

1. Release-Tag erzeugen und grüne CI-, Security- und Image-Checks prüfen.
2. `deploy` mit Ziel `staging` und dem Release-SHA ausführen.
3. Attestierungen, Runtime-Readiness und externe Abhängigkeiten werden geprüft.
4. `migrate --isolated --force` sperrt parallele Migrationen und führt sie
   genau einmal aus. Migrationen müssen expand/contract-kompatibel sein:
   Spalten zuerst hinzufügen, Code umstellen und erst in einem späteren
   Release entfernen.
5. Der Stack startet ausschließlich die verifizierten SHA-Tags.
6. Smoke-Tests prüfen HTTP, Login, Queue, Scheduler, Storage, Suche und
   ClamAV. Fehler lösen den automatischen App-Rollback auf den vorherigen SHA
   aus.
7. Nach fachlicher Staging-Abnahme wird derselbe SHA nach Production
   freigegeben.

## Rollback

`scripts/ops/deploy-release.sh` speichert den letzten erfolgreichen SHA unter
`/var/lib/erin/deployments`. Bei Preflight-, Migrations-, Start- oder
Smoke-Fehlern werden App-Dienste und Nginx auf diesen SHA zurückgesetzt.
Datenbankmigrationen werden nicht blind rückwärts ausgeführt; das Schema bleibt
durch expand/contract mit der vorherigen App kompatibel. Eine fachlich
destruktive Migration ist nicht releasefähig.

Jeder Lauf schreibt maschinenlesbare Evidenz nach
`storage/app/operations/evidence/deployments`. Der GitHub-Run, Freigabeperson,
Environment-Log und dieses Artefakt bilden den Auditnachweis.

## Entscheidung und Eskalation

- Deployment-Owner: im jeweiligen GitHub Environment hinterlegen.
- Rollback-Entscheidung: On-call Operations; bei Datenintegrität zusätzlich
  Incident Commander und Datenschutz.
- Notfallkontakt und Eskalationskanal: als geschütztes Environment-Secret bzw.
  internes Bereitschaftsdokument, niemals im öffentlichen Repository.
- Bei fehlender Attestierung, unklarer Migration oder rotem Smoke-Test wird
  nicht manuell „weitergeklickt“.
