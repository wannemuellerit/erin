# LiveKit für Erin-Videointerviews

## Entscheidung

Erin verwendet den selbst gehosteten LiveKit Server als WebRTC-SFU. Der Server
steht unter Apache-2.0 und verursacht keine Lizenzgebühr. LiveKit Agents, Cloud,
Ingress, SIP und Egress sind für den aktuellen 1:1-Interviewfall nicht
Bestandteil des Systems.

Der lokale Entwicklungsstack startet LiveKit 1.13.1 reproduzierbar über den
Image-Digest. Die Anwendung erzeugt kurzlebige JWT-Zugänge, verteilt den
E2EE-Schlüssel nur an berechtigte Interviewteilnehmer und verarbeitet signierte
Teilnahme-Webhooks.

## Lokaler Betrieb

Mit docker compose up -d startet zusätzlich:

- Signalisierung und Server-API auf ws://localhost:7880
- WebRTC über TCP 7881
- WebRTC über den lokalen UDP-Mux 7882
- maximal zwei Teilnehmer pro Raum
- Redis für Raumkoordination
- Webhooks an Erins lokalen Interview-Endpunkt

Der lokale ws-Endpunkt ist ausschließlich für localhost und nur mit
LIVEKIT_ALLOW_INSECURE_LOCAL=true zulässig. Produktion akzeptiert weiterhin
ausschließlich wss.

Status und Logs:

    docker compose ps livekit
    docker compose logs livekit

## Chat im Anruf

Der Interview-Chat nutzt LiveKits Text Streams über den WebRTC-Datenkanal. Er
ist durch denselben E2EE-Raumschlüssel wie Audio und Video geschützt. Nachrichten
werden im Browser als Text gerendert, auf 2.000 Zeichen begrenzt und nicht als
HTML interpretiert.

Der Chat ist absichtlich flüchtig: LiveKit persistiert keine Text-Streams,
später beitretende Teilnehmer erhalten keine frühere Historie und beim Verlassen
des Raums wird die lokale Anzeige geleert. Eine spätere Chat-Aufbewahrung
benötigt eine gesonderte Produktentscheidung zu Zweck, Einwilligung,
Aufbewahrungsfrist, Export und Löschung.

## Was für Produktion zusätzlich erforderlich ist

Ein WebRTC-Server allein genügt nicht für einen belastbaren Internetbetrieb.
Vor dem Go-live müssen vorhanden und getestet sein:

1. Ein eigener deutscher LiveKit-Host mit ausreichend CPU und Netzwerkbandbreite.
2. Eine öffentliche LiveKit-Domain mit vertrauenswürdigem TLS-Zertifikat; Erin
   erhält sie als wss-LIVEKIT_URL.
3. Eine eigene TURN-Domain mit TLS-Terminierung. Der Container stellt TURN/UDP
   und den internen TURN/TLS-Port bereit; der vorgeschaltete L4-Proxy terminiert
   das öffentliche TLS.
4. Offene Firewall-Ports: Signal-TCP zum TLS-Proxy, RTC-TCP 7881, der
   konfigurierte RTC-UDP-Bereich 50000–50100, TURN/UDP 3478 und TURN/TLS 5349.
5. Redis-Persistenz, Monitoring, Alarmierung, Kapazitätstest, Backups der
   Konfiguration und ein getesteter Update-/Rollback-Ablauf.
6. Ein realer Zwei-Browser-Test aus unterschiedlichen Netzen, einschließlich
   eines restriktiven Firmennetzes, um den TURN-Fallback nachzuweisen.
7. Betrieb, Telemetrie, DNS, TLS und Redis ausschließlich auf der freigegebenen
   deutschen Infrastruktur. Medien und Chat sind E2EE; Signalisierungsmetadaten
   bleiben dennoch für den selbst gehosteten Server sichtbar.

compose.production.yaml enthält dafür einen gepinnten LiveKit-Dienst mit Redis,
Zwei-Personen-Räumen, Erin-Webhook, RTC-Ports und eingebettetem TURN. Die
Variablen LIVEKIT_URL, LIVEKIT_API_KEY, LIVEKIT_API_SECRET und
LIVEKIT_TURN_DOMAIN sind verpflichtend; ohne sie lässt sich der
Produktionsstack nicht auflösen.

## Nicht enthalten

- Keine Aufzeichnung oder Transkription; dafür wäre LiveKit Egress plus eine
  eigene Einwilligungs- und Aufbewahrungslogik erforderlich.
- Kein RTMP-, WHIP- oder OBS-Eingang; dafür wäre Ingress erforderlich.
- Keine Telefonie; dafür wäre der SIP-Dienst erforderlich.
- Keine KI-Agenten. Die Teilnehmer sind ausschließlich Kandidat und
  Unternehmensnutzer.

## Abnahme

Vor Freigabe sind mindestens folgende Fälle nachzuweisen:

- Kandidat und berechtigter Unternehmensnutzer treten im erlaubten Zeitfenster
  bei, sehen und hören einander und können Chatnachrichten austauschen.
- Unberechtigte Nutzer, falsche Zeitfenster, unsichere URLs und ungültige Tokens
  werden abgewiesen.
- Kamera, Mikrofon, Bildschirmfreigabe, Gerätewechsel und Reconnect
  funktionieren.
- Direktes UDP, ICE/TCP und TURN/TLS werden jeweils kontrolliert getestet.
- Webhook-Wiederholungen erzeugen keine doppelten Anwesenheitsdaten.
- E2EE ist bei beiden Teilnehmern aktiv; ohne Schlüssel kommt keine Verbindung
  zustande.
