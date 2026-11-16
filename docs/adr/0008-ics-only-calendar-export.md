# ADR 0008: Kalenderexport ausschließlich über ICS

## Status

Akzeptiert am 12. August 2026.

## Entscheidung

Erin bietet bestätigte Interviewtermine ausschließlich als zeitlich begrenzt
signierte `.ics`-Datei an. Nutzer importieren diese Datei selbst in Google
Calendar, Microsoft Outlook, Apple Kalender oder einen anderen kompatiblen
Kalender.

Eine direkte Google- oder Microsoft-Anbindung ist derzeit nicht Teil des
Produkts. Es gibt deshalb keine Kalender-OAuth-Flows, Provider-Webhooks,
gespeicherten Zugriffs- oder Refresh-Tokens, Hintergrundsynchronisation oder
automatische Änderung bereits importierter Termine.

## Gründe

- Der ICS-Standard funktioniert anbieterunabhängig und ohne zusätzliche Konten.
- Erin muss keine langfristigen Kalenderberechtigungen oder Provider-Tokens
  verwalten.
- Einrichtung, Datenschutzprüfung, Support und Fehlerbehandlung bleiben
  deutlich einfacher.
- Nutzer entscheiden bewusst, in welchen Kalender sie einen Termin übernehmen.

## Verhalten und Grenzen

- Nur berechtigte Interviewteilnehmer erhalten einen signierten Download-Link.
- Die Datei enthält Beginn, Ende, Titel und die Erin-Interviewbeschreibung.
- Ein später in Erin geänderter oder abgesagter Termin aktualisiert eine bereits
  importierte Datei nicht automatisch. Nutzer laden die aktuelle Datei erneut
  herunter beziehungsweise ändern oder entfernen den Kalendereintrag selbst.
- Direkte Kalenderverbindungen, Provider-Auswahl und Synchronisationsstatus
  werden in der Oberfläche nicht angeboten.

## Mögliche spätere Wiedereinführung

Eine direkte Synchronisation kann später als eigenständiges Produktvorhaben neu
bewertet werden. Voraussetzung sind ein bestätigter Nutzerbedarf, Datenschutz-
und Sicherheitsfreigaben, ein klarer Widerrufs- und Löschprozess sowie echte
Abnahmetests mit Google- und Microsoft-Testmandanten. Die frühere experimentelle
Implementierung bleibt über die Git-Historie nachvollziehbar und wird nicht als
inaktiver Produktcode mitgeführt.
