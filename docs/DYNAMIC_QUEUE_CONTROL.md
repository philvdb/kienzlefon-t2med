# Dynamische Queue-Steuerung – Integrationshinweise

## Zielbelegung (noch nicht auf dem Praxisserver installiert)

| SIP | Arbeitsplatz | Praxisqueue | Sonderqueue |
| --- | --- | --- | --- |
| 201 | Backoffice | an, Stufe 0 | an |
| 202 | Zimmer 6 (Snom D715) | aus, Stufe 1 | aus |
| 203 | Tresen rechts | an, Stufe 2 | an |
| 204 | Tresen links | an, Stufe 3 | an |
| 300 | Sprechzimmer gemeinsam über FRITZ!Box | aus | an |

Die vorhandene 202 wird umgezogen. SIP, FRITZ!Box-Zuordnung, T2med-Client und GDT-Verzeichnis müssen zusammen umgestellt werden.

## Voraussetzungen

1. Kienzlefax Queue-only: KFX_PHONE_COUNT=4 mit 201–204 (201/202-Passwörter erhalten). In Kienzlefon [asterisk].queue_nebenstellen_anzahl=4 setzen.
2. Rottelefon deaktiviert, Reservierung [ivr].rote_nebenstelle="299"; eigene SIP-Nebenstelle 300 mit Passwort und [sonderqueue].zusaetzliche_nebenstellen=["300"].
3. Kernpatch patches/kienzlefon-dynamic-queue.patch auf den passenden Kienzlefon-Quellstand anwenden. Er erzwingt FIFO, erlaubt AMI-Agent-Schreibzugriff und schaltet mit [ivr].fachstellen_immer_erreichbar=true die IVR 9 rund um die Uhr frei.
4. Kienzlefon-AMI-Benutzer braucht write=agent zusätzlich zu seinen bisherigen Rechten für QueuePause und QueueAdd. Mit Kernpatch im Generator enthalten; AMI danach kontrolliert neu laden.
5. In der T2med-Konfiguration [telephony.extensions] in Reihenfolge 201,202,203,204,300 festlegen und queues=["praxis","kienzlefon-sonder"] einstellen; Clients und GDT auf neue Zuordnung prüfen.

In /etc/kienzlefon-t2med/config.toml ergänzen (bestehende Einstellungen erhalten):

~~~toml
[queue_control]
enabled = true
state_file = "/var/lib/kienzlefon-t2med/queue-selection.json"
default_practice = ["201", "203", "204"]
default_special = ["201", "203", "204", "300"]
~~~

## Verhalten

- QueuePause schaltet ein Mitglied pro Queue ohne Gesprächsabbruch und ohne Reload.
- Normaler Anruf: Kienzlefon-Staffel 201 → 202 → 203 → 204. Zugeschaltete Telefone dürfen bis zum nächsten Rufzyklus warten.
- Sonderqueue: Alle eingeschalteten Telefone klingeln ohne Staffelung. Vorhandene Anrufer werden berücksichtigt.
- Die 300 ist in der Sonderqueue statisch. Wird sie für normale Anrufe aktiviert, fügt die Middleware sie bei Bedarf dynamisch mit Penalty 3, Local/300@kienzlefon-queue-member und StateInterface PJSIP/300 hinzu.
- Mit FIFO-Kernpatch bekommt nur der jeweilige Queue-Kopf Rufangebote, bis er angenommen wurde oder die Queue verlässt. Danach folgt der nächste Anrufer.
- Bestätigte Änderungen werden in queue-selection.json gespeichert und nach Queue-Reload und AMI-Neuverbindung wiederhergestellt. Bei fehlender Datei werden die Standardwerte benutzt.
- Bedienung ohne MFA-Login, aber vorhandene Intranet-/Origin-/Token-Prüfung bleibt. Die letzte aktivierte Nebenstelle kann serverseitig nicht deaktiviert werden.

## Abnahme vor Produktivbetrieb

1. Bestehende Telefon- und GDT-Funktionen prüfen; danach 203, 204 und 300 neu registrieren und die physische Zuordnung von 202 wechseln.
2. Vier Wartende und nur 201 aktiv: 202 und 203 während der Warteschlange zuschalten. Der älteste Anrufer muss als erster angenommen werden; der nächste erst danach.
3. Schalter Normal und IVR 9 unabhängig testen; bei laufenden Gesprächen pausieren und wieder aktivieren.
4. Zwei Browser versuchen, die jeweils letzte aktive Nebenstelle auszuschalten: Der Server muss mindestens eine aktiv lassen.
5. Asterisk und Middleware nach verschiedenen Einstellungen neu starten und tatsächliche Queue-Mitgliedschaft sowie Kontrollkästchen abgleichen.
6. AMI-Verbindung ausfallen lassen: keine neuen Aktionen und keine falschen Erfolgsmeldungen.
7. IVR 9 außerhalb der Sprechzeiten mit [ivr].fachstellen_immer_erreichbar=true einschließlich Ansage prüfen.
8. Nach Kienzlefon-Upstream-Updates sicherstellen, dass der Kernpatch weiter angewendet wird oder upstream übernommen wurde.

Nicht Bestandteil: Handyweiterleitung, Zimmer-6-Zeitplanung oder automatische Änderung der laufenden Praxisinstallation.
