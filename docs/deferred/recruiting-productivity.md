# Zurückgestellter Bereich: Recruiting-Produktivität

## Status

Am 12. August 2026 vorerst aus dem Produkt entfernt. Erin bietet bis zu einer
erneuten, ausdrücklichen Produktentscheidung keine dedizierte Recruiter-Rolle
zur Einladung oder Zuweisung an.

Die bisherige Implementierung und ihre Daten werden nicht gelöscht. Dadurch
bleiben historische Datensätze nachvollziehbar und eine spätere Reaktivierung
ist ohne verlustbehaftete Datenmigration möglich. Bestehende historische
Mitgliedschaften mit der technischen Rolle 'recruiter' bleiben lesbar, die
Rolle kann über Oberfläche und HTTP-Validierung aber nicht neu vergeben werden.

## Bisheriger Umfang

Der Arbeitgeberbereich '/employer/productivity' bündelte:

- persönliche und teambezogene Wiedervorlagen mit Titel, Notiz, Zuständigkeit,
  Stelle, Priorität, Fälligkeit und Zeitzone;
- tägliche, wöchentliche und monatliche Wiederholungen;
- Erledigen, Wiederöffnen, um einen Tag verschieben und verwerfen;
- fällige In-App-, E-Mail- und Browser-Push-Benachrichtigungen mit
  idempotenter Zustellung;
- CSV- und XLSX-Kandidatenimporte mit Vorschau, Spaltenzuordnung, Validierung,
  Teilfehlern, Abbruch und einer Vorlage;
- einen mandantenbezogenen Activity Feed für Bewerbungen, Stellen,
  Nachrichten, Interviews, Einladungen, Importe und Erinnerungen;
- Realtime-Aktualisierungen des Activity Feeds über Reverb;
- mobile und tastaturbedienbare Oberflächen sowie Rollen- und
  Mandantentrennung.

## Technischer Ruhestand

- Der Navigationseintrag wurde entfernt.
- Der direkte Aufruf von '/employer/productivity' liefert 404.
- Die automatische Ausführung fälliger Recruiter-Erinnerungen wurde aus dem
  Scheduler entfernt.
- Alte Reminder-Deep-Links führen nicht mehr auf eine tote Produktseite;
  Import-Aktivitäten verweisen auf die Kandidatenübersicht.
- Neue Teammitglieder können nur als Admin oder Viewer eingeladen und
  zugewiesen werden.
- Controller, Modelle, Tabellen, Importjobs und die bisherige Vue-Seite bleiben
  vorerst im Repository, sind aber nicht mehr Teil des erreichbaren Produkts.

## Voraussetzungen für eine Reaktivierung

Vor einer erneuten Freischaltung müssen Zielgruppe und Verantwortlichkeiten
neu beschlossen, die Recruiter-Rolle ausdrücklich wieder eingeführt, Navigation
und Route reaktiviert sowie die bestehenden Funktions-, Browser-,
Berechtigungs- und Mandantentests erneut vollständig ausgeführt werden.
