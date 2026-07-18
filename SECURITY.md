# Sicherheitsrichtlinie

## Unterstützte Version

Sicherheitskorrekturen werden für den aktuellen `main`-Stand und die zuletzt
veröffentlichte Produktionsversion bereitgestellt.

## Vertrauliche Meldung

Bitte keine Sicherheitslücke, Zugangsdaten oder personenbezogenen Inhalte in
einem öffentlichen Issue veröffentlichen. Verwende stattdessen die
[privaten GitHub Security Advisories](https://github.com/wannemuellerit/erin/security/advisories/new).

Eine Meldung sollte ausschließlich notwendige technische Informationen,
betroffene Versionen, reproduzierbare Schritte und eine Einschätzung der
Auswirkung enthalten. Echte Kandidaten-, Firmen- oder Dokumentdaten sind zu
entfernen oder vollständig zu anonymisieren.

## Reaktionsziele

| Schweregrad | Erste Rückmeldung | Triage | Ziel für Abhilfe |
|---|---:|---:|---:|
| Kritisch | 4 Stunden | 8 Stunden | 24 Stunden |
| Hoch | 1 Arbeitstag | 2 Arbeitstage | 7 Tage |
| Mittel | 3 Arbeitstage | 5 Arbeitstage | 30 Tage |
| Niedrig | 5 Arbeitstage | 10 Arbeitstage | nächste geplante Version |

Die Frist kann bei komplexen Provider-, Legal- oder Datenmigrationsabhängigkeiten
abweichen. Risiko, Zwischenmaßnahmen und neuer Zieltermin werden dann
dokumentiert.

## Supply Chain

Composer-, npm-, GitHub-Action- und Docker-Abhängigkeiten werden durch
Dependabot überwacht. CI trennt Dependency-Audit, PHPStan, CodeQL für
JavaScript/TypeScript, Secret-Scan, Container-Scan und SBOM-Erzeugung.
Kritische oder hohe ungeklärte Befunde blockieren einen Release.

Ausnahmen benötigen eine verantwortliche Person, Begründung, Ablaufdatum und
ein Folge-Issue. Scanner-Ausgaben dürfen keine Secret-Werte,
Dokumentinhalte oder KI-Eingaben enthalten.
