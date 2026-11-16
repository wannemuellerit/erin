# Fachkräftesuche: Datenbank und Suchindex

Die Zahl veröffentlichter Profile wird aus der Datenbank berechnet. Die normale
Übersicht und strukturierte Filter werden direkt dort paginiert, nicht anhand
eventuell veralteter Suchindex-Zähler. Unveröffentlichte und gelöschte Profile
bleiben ausgeschlossen. Bei einem Seitenwechsel außerhalb des Ergebnisbereichs
wird auf Seite 1 zurückgeführt; Filterwechsel starten ebenfalls auf Seite 1.

Textsuche verwendet Meilisearch. Wenn nach Prüfung gegen die Datenbank weniger
Profile übrig bleiben als der Index für diese Seite meldet, wird die Suchanfrage
gegen die veröffentlichten Datenbankprofile wiederholt und eine Warnung ohne
Suchtext oder personenbezogene Daten protokolliert. Dieser seltene Fallback
nutzt Scouts Collection-Suche (Teilstrings statt Meilisearch-Toleranz) und kann
bei großen Datenmengen langsamer sein. Er ersetzt keinen gesunden Suchindex.

## Nach Wiederherstellung oder Datenbank-Neuaufbau

```sh
docker compose exec -T laravel php artisan erin:search:rebuild-candidates
docker compose up -d --no-deps horizon
docker compose exec -T laravel php artisan horizon:status
```

Der Neuaufbau ersetzt nur den konfigurierten Fachkräfte-Index; Originalprofile,
andere Indizes und Dateien bleiben erhalten. Er wartet auf erfolgreiche
Meilisearch-Aufgaben und verhindert parallele Neuaufbauten mittels Cache-Lock.
Während des Neuaufbaus kann die Textsuche kurz unvollständig sein; die normale
Profilübersicht bleibt verfügbar. Bei einem Fehler Ursache beheben und erneut
ausführen; Erfolg wird nicht vor Abschluss der Indexaufgaben gemeldet.

Der lokale Demo-Seeder unterdrückt Modellereignisse und damit Scout-Updates.
Deshalb synchronisiert er bei aktiviertem Meilisearch den Profilindex nun
ausdrücklich. Produktivdaten werden darüber nicht neu angelegt oder gelöscht.
Reguläre Profiländerungen benötigen weiterhin den laufenden Horizon-Worker.

## Lokale Prüfung am 11. September 2026

- 10 veröffentlichte Datenbankprofile, 10 Indexdokumente, alle IDs gültig.
- 14 Backend-Regressionstests (134 Assertions) erfolgreich: Pagination,
  Veröffentlichung, Filter, vollständig/teilweise veraltete Treffer und Neuaufbau.
- Chromium-Test erfolgreich: echte Anzahl, sichtbare Profilkarten und korrekte
  Nulltreffer bei einem unpassenden Länderfilter.
- PHPStan sowie TypeScript, ESLint, Übersetzungscheck und Frontend-Build erfolgreich.
