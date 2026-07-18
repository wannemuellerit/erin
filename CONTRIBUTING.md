# Mitarbeit an Erin

Erin verarbeitet Recruiting-, Kommunikations- und Dokumentdaten. Änderungen
müssen deshalb fachlich nachvollziehbar, mandantensicher und ohne reale
personenbezogene Testdaten erfolgen.

## Arbeitsablauf

1. Ein Issue mit messbaren Akzeptanzkriterien auswählen oder anlegen.
2. Einen Branch mit `feature/`, `fix/` oder `security/` vom aktuellen `main`
   erstellen.
3. Vor neuen UI-Bausteinen vorhandene Komponenten, Layouts und Design-Tokens
   prüfen und wiederverwenden.
4. Deutsche Texte mit echten Umlauten und `ß` schreiben; technische Schlüssel
   bleiben ASCII.
5. Einen Pull Request öffnen und vollständig erledigte Issues mit
   `Closes #<Nummer>` verknüpfen.

Direkte Pushes auf `main`, Force-Pushes und das Umgehen roter Pflichtprüfungen
sind nicht Teil des normalen Entwicklungsablaufs.

## Lokale Pflichtprüfungen

```bash
docker compose run --rm setup composer validate --strict
docker compose run --rm phpstan
docker compose run --rm pest
docker compose run --rm node-setup npm run lint:check
docker compose run --rm node-setup npm run format:check
docker compose run --rm node-setup npm run i18n:check
docker compose run --rm node-setup npm run types:check
docker compose run --rm node-setup npm run build
docker compose --profile tools run --rm playwright
```

Tests benötigen Positiv-, Berechtigungs-, Mandanten-, Retry- und
Missbrauchsfälle. Produktionsnahe Tests verwenden Fakes oder Testmodi und
niemals reale Zahlungen, Produktionsschlüssel oder echte Kandidatendaten.

## Reviews und Merge

- Mindestens eine unabhängige Freigabe ist erforderlich.
- Offene Review-Konversationen müssen aufgelöst sein.
- `tests`, `security` und der Produktionsimage-Build müssen grün sein.
- Sicherheits-, DPO-, Legal- oder externe Betriebsfreigaben dürfen nicht durch
  lokale Dummywerte ersetzt werden.
- Merge erfolgt als Squash oder linearer Rebase gemäß Repository-Regel.

Notfalländerungen benötigen ein dokumentiertes Break-glass-Issue, eine
nachträgliche unabhängige Prüfung und eine zeitnahe Rotation möglicherweise
betroffener Geheimnisse.
