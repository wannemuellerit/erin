# Analytics-Vertrag

Recruiting-Kennzahlen werden pro Firma und Bewerbung im Zeitraum `applied_at` in UTC berechnet. Bewerbungen zählen eindeutig; Interviewquote ist der Anteil der Bewerbungen mit mindestens einem Interview, Einstellungsquote der Anteil mit kanonischem Status `hired`, und Time-to-Hire ist der Mittelwert zwischen Bewerbung und menschlicher Einstellungsentscheidung.

Firmen- und Plattformaggregation bleiben getrennte Abfragen. Gelöschte Daten werden nicht aus Activity- oder Analytics-Snapshots rehydriert; personenbezogene Kleingruppen werden nicht exportiert. CSV-Exporte enthalten ausschließlich Stellenaggregate und werden mit Filterzeitraum auditiert.

`activity_entries` ist das versionierte Event-Fundament: jedes neue Event erhält UUID und `schema_version`; ein optionaler fachlicher Idempotenzschlüssel verhindert Duplikate aus wiederholten Jobs/Webhooks. Der allgemeine Feed lehnt sensible Payload-Felder ab. Neue Schema-Versionen müssen rückwärts lesbar bleiben und benötigen Contract-, Backfill- und Datenschutztests.

## Backfill und Datenqualität

Migration `2026_07_19_110000_backfill_activity_event_identifiers.php` markiert alle beim Rollout bereits vorhandenen Einträge mit `data_quality=backfilled`, ergänzt fehlende UUIDs und normalisiert ungültige Schema-Versionen auf Version 1. Neu beobachtete Ereignisse erhalten `data_quality=observed`; die Grenze wird vor dem Schemawechsel über die höchste bestehende ID fixiert, damit parallel später erzeugte Ereignisse nicht fälschlich als historisch gelten.

Vor und nach einem Release werden folgende Prüfungen dokumentiert; nach dem Backfill müssen alle drei Ergebnisse `0` sein:

```sql
select count(*) from activity_entries where event_uuid is null;
select count(*) from activity_entries where schema_version < 1 or schema_version is null;
select count(*) from (select event_uuid from activity_entries group by event_uuid having count(*) > 1) duplicates;
```

Ein Rollback entfernt ausschließlich die Qualitätsmarkierung. Die stabilen Event-UUIDs bleiben erhalten, damit bereits exportierte oder aggregierte Ereignisse weiterhin reproduzierbar referenziert werden können.
