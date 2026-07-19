# Kandidatenindex in Meilisearch

Der Scout-Index `candidate_profiles` enthält ausschließlich veröffentlichte, anonymisierte Suchdaten. Name, E-Mail, Telefonnummer, Stadt, Staatsangehörigkeit, Geschlecht, Geburtsdatum, Foto- und Dokumentpfade werden weder durch `toSearchableArray()` ausgegeben noch als filterbare Felder konfiguriert.

## Initialer Aufbau und Wiederherstellung

Im laufenden Docker-Stack werden die Indexeinstellungen zuerst synchronisiert und danach die veröffentlichten Profile importiert:

```bash
docker compose exec -T laravel php artisan scout:sync-index-settings
docker compose exec -T laravel php artisan scout:import "App\\Models\\CandidateProfile"
```

Für einen vollständigen Neuaufbau kann der bestehende Index vorher geleert werden:

```bash
docker compose exec -T laravel php artisan scout:flush "App\\Models\\CandidateProfile"
docker compose exec -T laravel php artisan scout:sync-index-settings
docker compose exec -T laravel php artisan scout:import "App\\Models\\CandidateProfile"
```

## Inkrementelle Synchronisation

Laravel Scout synchronisiert Profiländerungen nach dem Speichern automatisch. `CandidateProfile::shouldBeSearchable()` erlaubt nur Profile mit `published_at`; beim Zurückziehen der Veröffentlichung wird der Datensatz deshalb entfernt.

Die Suche kombiniert Scout/Meilisearch mit serverseitiger Mandantenautorisierung. AI Matching akzeptiert nur eine Stelle des aktiven Unternehmens und berechnet den versionierten Match-Breakdown nach der globalen Treffermenge, bevor paginiert wird.

## Prüfung

```bash
docker compose exec -T laravel php artisan scout:status "App\\Models\\CandidateProfile"
docker compose --profile tools run --rm pest sh docker/php/test.sh tests/Feature/Employer/CandidateSearchTest.php
```
