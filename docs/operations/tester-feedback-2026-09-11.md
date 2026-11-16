# Testerfeedback – Prüfung am 11.09.2026

Alle optischen Hinweise wurden als **Dark-Mode-Feedback** behandelt. Grundlage ist der aktuelle lokale Projektstand, nicht zwangsläufig dieselbe Version oder Serverkonfiguration wie beim Tester. Helle Flächen wurden nicht pauschal erzwungen.

## Korrigiert

| Bereich | Ergebnis |
| --- | --- |
| Fehlende Rückmeldungen | Portalweit sichtbare Erfolgs-, Warn- und Fehlermeldungen, einschließlich Validierungsfehlern aus anderen Profil-Tabs. Unterstützt Session-Meldungen und die nativen Inertia-Meldungen der Kontoeinstellungen. Keine HTML-Ausführung in Meldungen. |
| Unternehmensstandorte | Bestehende Standorte behalten beim Speichern ihre IDs und damit Stellen-/Team-Zuordnungen. Fremde Standort-IDs werden abgewiesen. Neue IDs werden nach dem Speichern ins Formular übernommen. Auch das Entfernen des letzten Standorts per Multipart-Formular funktioniert. Nicht übermittelte Standortdaten werden nicht mehr beiläufig gelöscht. Bewusst entfernte Standorte verlieren weiterhin ihre Zuordnungen. |
| Fehlende Standortauswahl bei Stellen | Wenn noch kein Firmenstandort vorhanden ist, führt ein Hinweis zur Standortpflege. Es werden keine Standorte erfunden. |
| E-Mail-Empfehlung/Teameinladung | SMTP-Transportfehler liefern eine verständliche Rückmeldung statt einer 500-Seite. Bei Einladungen steht ausdrücklich, dass die Einladung gespeichert, aber nicht versendet wurde; erneutes Senden bleibt möglich. |
| Abrechnung | Stripe-API-Fehler werden verständlich angezeigt, ohne Zahlungsbestätigung zu behaupten. Vor erneutem Versuch soll der Abrechnungsstatus geprüft werden. Enterprise-Kontakt führt jetzt zum Kontaktformular statt zu einem deaktivierten Button. |
| Erwartete 422-Ablehnungen | Schreibaktionen innerhalb von Inertia zeigen Geschäftsregel-Ablehnungen im Portal; JSON/API-Antworten behalten ihre Fehlercodes. Doppelt zugeordnete CSV-Spalten liefern einen konkreten Validierungsfehler. |
| Dark Mode | Verbliebene feste Schriftfarben im Dokumentenmanager, in Zusatzfeldern des Stelleneditors und beim Duplizieren von Stellen durch Theme-Farben ersetzt. |
| Bedienbarkeit | Zeiger für aktive Buttons/Auswahlfelder, Fokus-/Hoverzustände für Profil-Tabs, Skills und Führerscheine. Analytics deaktiviert den Button während des Ladens und zeigt danach den tatsächlich angewendeten Zeitraum. |

## Bereits vorhanden oder aktuell nicht reproduziert

| Testerhinweis | Bewertung |
| --- | --- |
| Fachkräftesuche zeigt 500/keine Karten | Bereits vor dieser Prüfung korrigiert: echte Profilzahlen, konsistente Suche und wiederhergestellter Suchindex. Erneuter Browsertest gehört zur Prüfung. |
| Verfügbarkeiten werden nicht gespeichert | Backend-Roundtrip mit Neuladen und erneutem Speichern erfolgreich. Überlappungen und Ende vor Beginn werden abgewiesen, vorhandene Zeiten bleiben dabei erhalten. Versteckte Pflichtfeldfehler sind durch die neue zentrale Anzeige sichtbar. Ein spezieller Fehler in der Chrome-Version des Testers ist damit nicht ausgeschlossen. |
| Profil veröffentlichen reagiert nicht | Veröffentlichung hat Prüfbedingungen, unter anderem Profilvollständigkeit und Pflichtangaben. Ablehnungen werden jetzt sichtbar; die Prüfungen wurden nicht entfernt. |
| Stellen lassen sich nicht löschen | Löschaktion ist bereits in der Stellenübersicht vorhanden. Berechtigungen und fachliche Einschränkungen gelten weiterhin. |
| Nur manche Bewerbungsstatus funktionieren | Der aktuelle Ablauf bietet erlaubte Statusübergänge an und prüft Berechtigungen/Konkurrenzänderungen. Nicht jede beliebige Statuskombination ist zulässig. Fehler dürfen nun nicht mehr unsichtbar bleiben. |
| Dunkle/unlesbare Standardseiten | Die automatisierte Dark-Mode-Prüfung von öffentlichen Seiten, Fachkraft-, Unternehmens- und Adminbereichen ist grün. Profil-Tabs, Stelleneditor, neuer Standort und Benachrichtigungsmenü zusätzlich geprüft. Das ist keine Garantie für jeden denkbaren Datensatz, Hoverzustand oder Browser. |
| Bestätigungsmail führt zur Übersicht | Für ein noch unbestätigtes Konto bestätigt ein Regressionstest die Rückkehr zu den Kontoeinstellungen samt Versandhinweis. Bereits bestätigte Konten werden vom Authentifizierungssystem zur Übersicht geführt. |

## Noch offen / bewusst nicht freigeschaltet

- **Echter E-Mail-Versand:** SMTP-Zugang und Zustellung in der Testerumgebung separat prüfen. Transportausfälle und Wiederholung wurden mit Mocks geprüft; es wurden keine echten Empfänger angeschrieben. Die ursprünglichen 500-Ursachen sind ohne Fehlerprotokoll dieser Umgebung nicht abschließend bewiesen.
- **Stripe und Zusatzkontingente:** Integration und Kaufoberflächen sind vorhanden; verfügbare Aktionen hängen an Tarif, Stripe-Preis-IDs und Freigaben. Keine produktiven Schlüssel ergänzt, keine realen Käufe ausgelöst. Reale Testmodus-Checkouts, Webhooks und Abrechnungsabgleich bleiben erforderlich. Fehlerbehandlung ersetzt keine Einrichtung.
- **KI-Studio:** Provideranbindung ist implementiert, ein fehlender API-Zugang oder fehlende Freigabe für sensible Daten macht daraus aber keinen betriebsbereiten Dienst. Keine KI-Freigabe und keine Übermittlung von Testerdaten vorgenommen.
- **Weitere Profilsprachen:** Russisch, Italienisch und Französisch bzw. Freitext sind Erweiterungswünsche; nicht mit zusätzlichen Oberflächensprachen verwechseln. In dieser Fehlerkorrektur nicht ergänzt.
- **Supportticket selbst schließen:** Fehlende Self-Service-Funktion, kein behobener Fehler. Ein eigener Ablauf einschließlich Berechtigungen und Ticketing-Synchronisierung bleibt zu definieren und umzusetzen.
- **Recruiting-Zentrale:** Im aktuellen Produktstand bewusst stillgelegt. Die Importvalidierung wurde verbessert, die alte Oberfläche aber nicht reaktiviert.
- **Optische Präferenzen:** Weißer Benachrichtigungshintergrund/hellblaue Navigation wurden nicht übernommen. Dark Mode bleibt dunkel und verwendet lesbare Theme-Farben.
- Neue Backend-Fehlermeldungen sind Deutsch/Englisch vorhanden; die weiteren bestehenden Sprachkataloge verwenden dafür vorerst englische Ersatztexte.

## Prüfbelege

- Vollständige Backend-Suite: **813 Tests, 17.144 Assertions erfolgreich**. Danach ergänzter Verifikationstest und Multipart-Fall: **13 fokussierte Tests, 110 Assertions erfolgreich**.
- PHPStan: **531 Dateien ohne Fehler**.
- Dark-Mode-Seitentests: **4 erfolgreich**, insgesamt 42 Seitenaufrufe.
- Zusätzliche Browser-Regressionen: **7 erfolgreich** (Profil-Tabs, Stelleneditor/Standort, Glockenmenü, sichere Session-/Inertia-Rückmeldungen und bestehende Merge-/Suchtests).
- TypeScript, ESLint, Formatprüfung, Übersetzungsprüfung und Produktionsbuild erfolgreich; bekannter Hinweis zu großen JavaScript-Bundles bleibt bestehen.
- Keine produktive Zahlung, kein echter SMTP-Zustelltest, kein Live-KI-Aufruf. Nicht die gesamte historische Browser-Suite ausgeführt.

## So testest du es

1. Portal hart neu laden und unter Einstellungen **Dunkel** wählen. Profil-Tabs, Dokumenten-Upload, Stelleneditor und Glockenmenü auf Lesbarkeit prüfen.
2. Firmenstandort anlegen, speichern und erneut speichern. Er muss einmal vorhanden bleiben und in der Stellenbearbeitung auswählbar sein. Bestehende Stellenzuordnungen dürfen sich nicht ändern.
3. Eine gültige Verfügbarkeit speichern und neu laden. Danach eine ungültige Zeitspanne versuchen: sichtbare Fehlermeldung, bisherige Zeiten bleiben erhalten.
4. Firmen-/Rechnungsdaten und einen Stellenentwurf speichern. Erfolg bzw. konkrete Pflichtfeldfehler müssen sichtbar werden. Analytics-Zeitraum anwenden und die Rückmeldung prüfen.
5. Mit eigenen Testkonten SMTP und Stripe-Testmodus separat prüfen. Bei Versandfehlern darf keine Erfolgsmeldung erscheinen; Enterprise muss das Kontaktformular öffnen.
