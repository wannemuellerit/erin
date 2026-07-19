# Capability-Rollenmatrix

Backend, Inertia-Props und Navigation verwenden dieselben Capability-Schlüssel
aus `App\Enums\Capability`. Eine ausgeblendete UI-Aktion ersetzt niemals die
serverseitige Prüfung durch `capability:*`, Policy oder Form Request.

| Bereich | Owner | Admin | Recruiter | Viewer | Support | Superadmin |
|---|---:|---:|---:|---:|---:|---:|
| Kandidaten, Jobs, Bewerbungen lesen | ✓ | ✓ | ✓ | ✓ | – | ✓ |
| Kandidaten einladen, Jobs/Bewerbungen ändern | ✓ | ✓ | ✓ | – | – | ✓ |
| Nachrichten, Interviews, Visa bearbeiten | ✓ | ✓ | ✓ | – | – | ✓ |
| Firma und Team bearbeiten | ✓ | ✓ | – | – | – | ✓ |
| Eigentümerschaft und Abrechnung ändern | ✓ | – | – | – | – | ✓ |
| Plattform und Support verwalten | – | – | – | – | Support | ✓ |

Supportansichten einer Nutzerpersona sind immer schreibgeschützt. Der globale
`BlockSupportWrites`-Schutz blockiert dabei unabhängig von ausgeblendeten
Schaltflächen jede mutierende HTTP-Methode; Einstieg, Grund und Ende werden
auditiert.

## Sicherheitsregeln

- Der aktive Firmenkontext muss zu einer angenommenen Mitgliedschaft gehören.
- Fremde IDs werden innerhalb der aktiven Firma aufgelöst und führen zu 404/403.
- Viewer besitzen ausschließlich Lese-Capabilities.
- Recruiter erhalten weder Billing- noch Team-/Eigentümerrechte.
- Admins können das Team verwalten, aber keine Eigentümerschaft oder Abrechnung.
- Der letzte aktive Superadmin und der letzte Firmeninhaber dürfen nicht entfernt
  oder herabgestuft werden.
