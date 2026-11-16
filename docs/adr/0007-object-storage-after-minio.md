# ADR 0007: Objektspeicher nach der MinIO-Archivierung

## Status

Akzeptiert am 10. August 2026. MinIO wird weder lokal noch produktiv weiter
betrieben.

## Kontext

Das öffentliche Repository `minio/minio` wurde am 25. April 2026 archiviert;
der letzte veröffentlichte Security-Release stammt vom 15. Oktober 2025. Ein
unveränderter Single-Node-MinIO-Container erfüllt deshalb Erins Anforderungen
an laufende Sicherheitswartung, Versionierung, Restore und Betriebsnachweise
nicht mehr.

Primärquellen:

- <https://github.com/minio/minio/releases>
- <https://github.com/seaweedfs/seaweedfs>
- <https://github.com/seaweedfs/seaweedfs/releases>
- <https://github.com/deuxfleurs-org/garage/blob/main-v2/doc/book/reference-manual/s3-compatibility.md>
- <https://github.com/rustfs/rustfs/releases>
- <https://github.com/ceph/ceph/blob/main/doc/radosgw/s3.rst>

## Entscheidung

`compose.yaml` verwendet SeaweedFS 4.40 mit unveränderlichem Image-Digest nur
als lokalen S3-kompatiblen Entwicklungs- und Testdienst. Der Bucket wird beim
Start privat mit expliziten Zugangsdaten angelegt. Ein aktiver Smoke-Test prüft
Schreiben, direktes Lesen, signierten Download und Löschen über Laravels echten
S3-Treiber.

`compose.production.yaml` enthält keinen Objektspeicher. Produktion verlangt
einen getrennt betriebenen oder verwalteten S3-kompatiblen Dienst über
`AWS_ENDPOINT`. Das Security-Gate bleibt geschlossen, bis bucket-begrenzte
Credentials, private Sichtbarkeit, Versionierung, Verschlüsselung,
Zugriffsprotokollierung und eine getrennte Offsite-Kopie durch eine
unveränderliche Evidenz belegt sind. AWS S3, ein verwalteter S3-Dienst oder ein
fachgerecht betriebener Ceph-RGW-/SeaweedFS-Cluster können diese Schnittstelle
erfüllen; der Anbieter wird nicht im Anwendungscode festgeschrieben.

Garage wird für diesen Schutzbedarf nicht als Standard gewählt, weil dessen
eigene Kompatibilitätsmatrix unter anderem fehlende Objektversionierung,
Bucket-Policies, Object Lock und serverseitige Bucket-Verschlüsselung ausweist.
RustFS bleibt trotz aktiver Entwicklung vorerst ausgeschlossen, solange die
Releases als Beta ausgewiesen sind. Ceph RGW ist funktional geeignet, aber für
den lokalen Ein-Container-Entwicklungsstack unnötig komplex.

## Migration und Folgen

- Die bisherigen lokalen MinIO-Container enthielten zum Migrationszeitpunkt
  keine Objekte und wurden entfernt; das alte Docker-Volume bleibt zunächst
  als reversible Sicherheitskopie bestehen.
- Backup- und Restore-Abläufe verwenden die offizielle AWS CLI v2 statt des
  archivierten MinIO-Clients.
- Der lokale verschlüsselte Restore-Drill startet SeaweedFS in einem internen
  Docker-Netz und flüchtigem `tmpfs`; seine Evidenz bleibt ausdrücklich
  synthetisch und entsperrt kein Produktionsgate.
- Ein Anbieterwechsel ändert Konfiguration und Betriebsnachweise, nicht die
  Laravel-Domainlogik oder gespeicherte Objektpfade.
