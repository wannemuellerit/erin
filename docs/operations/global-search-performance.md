# Global-Search-Performancevertrag

Die globale Suche startet erst ab zwei sichtbaren Zeichen und wartet im Client
250 ms auf weitere Eingaben. Jede Ergebnisgruppe liefert höchstens sechs
Treffer; direkte Routen und Policies bleiben die verbindliche zweite
Autorisierungsschicht für jeden Deep Link.

## Releasebudget

- API-P95: höchstens 300 ms bei 12 Monaten produktionsähnlichen Daten.
- UI: erstes Ergebnis spätestens 600 ms nach der letzten Eingabe.
- Datenprofil: mindestens 100.000 Kandidaten, 20.000 Stellen und 200.000
  Bewerbungen, gleichmäßig auf mindestens 20 Mandanten verteilt.
- Keine Antwort darf Namen, E-Mail-Adressen oder Datensätze eines fremden
  Mandanten enthalten; Kandidaten werden Firmen ausschließlich anonymisiert
  angezeigt.

## Reproduzierbare Messung

1. Einen anonymisierten, synthetischen Datensatz mit dem oben beschriebenen
   Volumen in Staging einspielen.
2. Pro Rolle mindestens 500 Suchanfragen mit Begriffslängen von 2, 3, 8 und 40
   Zeichen ausführen; Warm- und Cold-Cache getrennt messen.
3. P50, P95, P99, Fehlerrate und Slow Queries als Release-Evidence ablegen.
4. Den Release sperren, wenn P95 das Budget überschreitet oder ein
   Mandanten-/Identitätsleck gefunden wird.

Lokal sichern `GlobalSearchTest` die Rollen- und Mandantengrenzen und
Playwright den Command-Palette-, Tastatur- und Mobile-Flow ab. Die Messung mit
dem produktionsähnlichen 12-Monats-Datensatz bleibt ein Staging-Release-Gate.
