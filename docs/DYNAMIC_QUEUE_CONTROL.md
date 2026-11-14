# Dynamische Queue-Steuerung – Integrationshinweise

## Zielbelegung (MFA 201–204 bereits produktiv; Sprechzimmer 301–303 noch einzurichten)

| SIP | Arbeitsplatz | Praxisqueue | Sonderqueue |
| --- | --- | --- | --- |
| 201 | Backoffice | an, Stufe 0 | an |
| 202 | Zimmer 6 (Snom D715) | an, Stufe 1 | an |
| 203 | Tresen rechts | an, Stufe 2 | an |
| 204 | Tresen links | an, Stufe 3 | an |
| 300 | Deaktiviertes rotes Telefon (reserviert) | – | – |
| 301 | Sprechzimmer 1, Gigaset über FRITZ!Box | aus | an |
| 302 | Sprechzimmer 2, Snom direkt | aus | an |
| 303 | Sprechzimmer 3, Snom direkt | aus | an |

Die 202 wurde bereits auf den neuen Snom D715 in Zimmer 6 umgezogen. 201–204 sind registriert und direkt erreichbar. Die T2med-Client-/GDT-Zuordnung ist vor Produktivsetzung gesondert zu überprüfen.

## Voraussetzungen

1. Kienzlefax Queue-only: KFX_PHONE_COUNT=4 mit 201–204 (201/202-Passwörter erhalten). In Kienzlefon [asterisk].queue_nebenstellen_anzahl=4 setzen.
2. Rottelefon bleibt deaktiviert, Reservierung [ivr].rote_nebenstelle="300". Unter [zusaetzliche_nebenstellen] die SIP-Nebenstellen ["301","302","303"] mit individuellen Passwörtern anlegen und unter [sonderqueue].zusaetzliche_nebenstellen=["301","302","303"] aufnehmen.
3. Kernpatch patches/kienzlefon-dynamic-queue.patch auf den passenden Kienzlefon-Quellstand anwenden. Er erzwingt FIFO, erlaubt AMI-Agent-Schreibzugriff und schaltet mit [ivr].fachstellen_immer_erreichbar=true die IVR 9 rund um die Uhr frei.
4. Kienzlefon-AMI-Benutzer braucht write=agent zusätzlich zu seinen bisherigen Rechten für QueuePause und QueueAdd. Mit Kernpatch im Generator enthalten; AMI danach kontrolliert neu laden.
5. In der T2med-Konfiguration [telephony.extensions] in Reihenfolge 201,202,203,204,301,302,303 festlegen, nicht 300; queues=["praxis","kienzlefon-sonder"] einstellen. Clients/GDT auf tatsächliche Zuordnung prüfen.

In /etc/kienzlefon-t2med/config.toml ergänzen (bestehende Einstellungen erhalten):

~~~toml
[queue_control]
enabled = true
state_file = "/var/lib/kienzlefon-t2med/queue-selection.json"
default_practice = ["201", "202", "203", "204"]
default_special = ["201", "202", "203", "204", "301", "302", "303"]
~~~

## Verhalten

- QueuePause schaltet ein Mitglied pro Queue ohne Gesprächsabbruch und ohne Reload.
- Normaler Anruf: Kienzlefon-Staffel 201 → 202 → 203 → 204. Zugeschaltete Telefone dürfen bis zum nächsten Rufzyklus warten.
- Sonderqueue: Alle eingeschalteten Telefone klingeln ohne Staffelung. Vorhandene Anrufer werden berücksichtigt.
- 301–303 sind eigenständige Mitglieder der Sonderqueue. Das reservierte rote Telefon 300 ist nicht registriert. Falls ein Sprechzimmer zusätzlich für normale Anrufe aktiviert wird, legt die Middleware bei Bedarf dynamisch ein Mitglied mit Local/<Nebenstelle>@kienzlefon-queue-member, StateInterface PJSIP/<Nebenstelle> und Penalty 3 an; dieses Verhalten bedarf eines zusätzlichen Integrationstests.
- Mit FIFO-Kernpatch bekommt nur der jeweilige Queue-Kopf Rufangebote, bis er angenommen wurde oder die Queue verlässt. Danach folgt der nächste Anrufer.
- Bestätigte Änderungen werden in queue-selection.json gespeichert und nach Queue-Reload und AMI-Neuverbindung wiederhergestellt. Bei fehlender Datei werden die Standardwerte benutzt.
- Bedienung ohne MFA-Login, aber vorhandene Intranet-/Origin-/Token-Prüfung bleibt. Die letzte aktivierte Nebenstelle kann serverseitig nicht deaktiviert werden.

## Abnahme vor Produktivbetrieb

1. MFA-Nebenstellen 201–204 sind bereits registriert und getestet. Sprechzimmer 301 über FRITZ!Box und 302/303 direkt an Asterisk registrieren und alle internen Richtungen testen.
2. Vier Wartende und nur 201 aktiv: 202 und 203 während der Warteschlange zuschalten. Der älteste Anrufer muss als erster angenommen werden; der nächste erst danach.
3. Schalter Normal und IVR 9 unabhängig testen; bei laufenden Gesprächen pausieren und wieder aktivieren.
4. Zwei Browser versuchen, die jeweils letzte aktive Nebenstelle auszuschalten: Der Server muss mindestens eine aktiv lassen.
5. Asterisk und Middleware nach verschiedenen Einstellungen neu starten und tatsächliche Queue-Mitgliedschaft sowie Kontrollkästchen abgleichen.
6. AMI-Verbindung ausfallen lassen: keine neuen Aktionen und keine falschen Erfolgsmeldungen.
7. IVR 9 außerhalb der Sprechzeiten mit [ivr].fachstellen_immer_erreichbar=true einschließlich Ansage prüfen.
8. Nach Kienzlefon-Upstream-Updates sicherstellen, dass der Kernpatch weiter angewendet wird oder upstream übernommen wurde.

Nicht Bestandteil: Handyweiterleitung, Zimmer-6-Zeitplanung oder automatische Änderung der laufenden Praxisinstallation.
