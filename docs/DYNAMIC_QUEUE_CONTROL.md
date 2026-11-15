# Dynamische Queue-Steuerung – Integrationshinweise

## Zielbelegung (alle sieben Nebenstellen bereits produktiv registriert und intern getestet)

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

Die 202 wurde auf den neuen Snom D715 in Zimmer 6 umgezogen. Alle sieben Nebenstellen sind registriert und interne Testanrufe funktionieren. Die T2med-Client-/GDT-Zuordnung ist vor Produktivsetzung gesondert zu überprüfen.

## Voraussetzungen

1. Kienzlefax Queue-only: KFX_PHONE_COUNT=4 mit 201–204 (201/202-Passwörter erhalten). In Kienzlefon [asterisk].queue_nebenstellen_anzahl=4 setzen.
2. Rottelefon bleibt deaktiviert, Reservierung [ivr].rote_nebenstelle="300". Unter [zusaetzliche_nebenstellen] die SIP-Nebenstellen ["301","302","303"] mit individuellen Passwörtern anlegen und unter [sonderqueue].zusaetzliche_nebenstellen=["301","302","303"] aufnehmen.
3. Der Kernpatch `patches/kienzlefon-dynamic-queue.patch` ergänzt die dauerhafte, generatorfeste Progressive-Penalty-Korrektur, FIFO, AMI-Agent-Rechte und eine optionale IVR-9-Erreichbarkeit außerhalb der Sprechzeit. **Die Checkout-/Patch-Installation des Kienzlefon-Kerns ist getrennt von der Middleware-Installation; den Patch nicht ungetestet direkt auf der produktiven VM anwenden.**
4. Der vorhandene Kienzlefon-AMI-Benutzer benötigt zusätzlich `write=agent` für `QueuePause` und `QueueAdd`. Bis der Kernpatch eingebaut ist, das bestehende generierte `/etc/asterisk/manager_kienzlefon_middleware.conf` **gezielt manuell** ergänzen und `manager reload` ausführen. Bei späterer Neugenerierung kann die manuelle Änderung verloren gehen.
5. In der T2med-Konfiguration [telephony.extensions] in Reihenfolge 201,202,203,204,301,302,303 festlegen, nicht 300; queues=["praxis","kienzlefon-sonder"] einstellen. Clients/GDT auf tatsächliche Zuordnung prüfen.

In /etc/kienzlefon-t2med/config.toml ergänzen (bestehende Einstellungen erhalten):

~~~toml
[queue_control]
enabled = true
state_file = "/var/lib/kienzlefon-t2med/queue-selection.json"
default_practice = ["201", "202", "203", "204"]
default_special = ["201", "202", "203", "204", "301", "302", "303"]
~~~

## Arbeitsplatz-Auswahl für Click-to-Call (15.11.2026)

Die Arbeitsplatz-Auswahl kommt aus `[clients.<id>]` in `/etc/kienzlefon-t2med/config.toml`, nicht aus den Queue-Checkboxen. Die IDs werden für ausgehende Gespräche, Tel-/Callto-Launcher und GDT verwendet. Bereits vorhandene Client-IDs `backoffice`, `tresen-rechts` und `tresen-links` unverändert beibehalten. Die Zuordnung in `[telephony.extensions]` muss dieselben sieben Nummern enthalten.

**Festgelegte Plätze:**

| Client-ID | Anzeigename | SIP-Nebenstelle | GDT-Ordner (Vorschlag, bei vorhandenen Plätzen Bestand erhalten) |
| --- | --- | --- | --- |
| `backoffice` | Backoffice | `201` | `backoffice` |
| `zimmer6` | Zimmer 6 | `202` | `zimmer6` |
| `tresen-rechts` | Tresen rechts | `203` | `tresen-rechts` |
| `tresen-links` | Tresen links | `204` | `tresen-links` |
| `sprechzimmer1` | Sprechzimmer 1 | `301` | `sprechzimmer1` |
| `sprechzimmer2` | Sprechzimmer 2 | `302` | `sprechzimmer2` |
| `sprechzimmer3` | Sprechzimmer 3 | `303` | `sprechzimmer3` |

**Client-TOML:**

~~~toml
[clients.backoffice]
label = "Backoffice"
extension = "201"
directory = "backoffice"

[clients.zimmer6]
label = "Zimmer 6"
extension = "202"
directory = "zimmer6"

[clients.tresen-rechts]
label = "Tresen rechts"
extension = "203"
directory = "tresen-rechts"

[clients.tresen-links]
label = "Tresen links"
extension = "204"
directory = "tresen-links"

[clients.sprechzimmer1]
label = "Sprechzimmer 1"
extension = "301"
directory = "sprechzimmer1"

[clients.sprechzimmer2]
label = "Sprechzimmer 2"
extension = "302"
directory = "sprechzimmer2"

[clients.sprechzimmer3]
label = "Sprechzimmer 3"
extension = "303"
directory = "sprechzimmer3"
~~~

Die bisherigen `[clients.*]`-Abschnitte **ersetzen** (nicht zusätzlich dieselben Namen nochmals definieren). Für bestehende GDT-Verzeichnisse den tatsächlichen Wert von `directory` beibehalten und gegebenenfalls die bestehende T2med-Gerätezuordnung nicht verändern. Neue GDT-Verzeichnisse im konfigurierten `[gdt].root` anlegen und dem Dienstbenutzer `kienzlefon-t2med` schreibbar bereitstellen. Diese Verzeichnisse müssen auch dann existieren, wenn eine neue Nebenstelle zunächst nur für Click-to-Call und noch nicht für den Aktenaufruf vorgesehen ist.

Nach Bearbeitung:

~~~bash
sudo -u kienzlefon-t2med php /opt/kienzlefon-t2med/bin/server.php \
  --config=/etc/kienzlefon-t2med/config.toml --check-config
systemctl restart kienzlefon-t2med.service
curl -fsS http://127.0.0.1:8766/api/v1/bootstrap | jq '.clients'
~~~

**Achtung:** Die Bootstrap-Antwort enthält außerdem ein Sitzungstoken. Zur Kontrolle nur `.clients` ausgeben, nicht die ungekürzte JSON-Antwort weitergeben. Die Browser-Auswahl merkt sich den zuletzt gewählten Arbeitsplatz lokal. Bereits installierte `tel:`-/`callto:`-Desktop-Launcher müssen bei einer geänderten Client-ID/Telefonnummer mit den neuen Parametern neu eingerichtet werden; sonst kann die absichernde `expected_extension`-Prüfung eine alte Registrierung ablehnen. Die SIP-Endgeräte selbst werden durch diese Client-Konfiguration nicht verändert.

## Verhalten

- QueuePause schaltet ein Mitglied pro Queue ohne Gesprächsabbruch und ohne Reload.
- Normaler Anruf: Kienzlefon-Staffel 201 → 202 → 203 → 204. Zugeschaltete Telefone dürfen bis zum nächsten Rufzyklus warten.
- Sonderqueue: Alle eingeschalteten Telefone klingeln ohne Staffelung. Vorhandene Anrufer werden berücksichtigt.
- 301–303 sind eigenständige Mitglieder der Sonderqueue. Das reservierte rote Telefon 300 ist nicht registriert. Falls ein Sprechzimmer zusätzlich für normale Anrufe aktiviert wird, legt die Middleware bei Bedarf dynamisch ein Mitglied mit Local/<Nebenstelle>@kienzlefon-queue-member, StateInterface PJSIP/<Nebenstelle> und Penalty 3 an; dieses Verhalten bedarf eines zusätzlichen Integrationstests.
- Mit FIFO-Kernpatch bekommt nur der jeweilige Queue-Kopf Rufangebote, bis er angenommen wurde oder die Queue verlässt. Danach folgt der nächste Anrufer.
- Bestätigte Änderungen werden in queue-selection.json gespeichert und nach Queue-Reload und AMI-Neuverbindung wiederhergestellt. Bei fehlender Datei werden die Standardwerte benutzt.
- Bedienung ohne MFA-Login, aber vorhandene Intranet-/Origin-/Token-Prüfung bleibt. Die letzte aktivierte Nebenstelle kann serverseitig nicht deaktiviert werden.


## Erprobung der Queue-Steuerung (15.11.2026)

Fonial funktioniert wieder. Ein externer Testanruf bestätigte mit dem manuellen `QUEUE_MAX_PENALTY`-Fix die korrekte Rufstaffelung 201, 202, 203, 204. Die AMI-Steuerung kann unabhängig davon an den internen SIP-Telefonen erprobt werden. Die bereits manuell gesetzten `QUEUE_MAX_PENALTY`-Regeln bleiben zunächst bestehen; den Kienzlefon-Installer beziehungsweise `kienzlefon-asterisk` dabei **nicht erneut ausführen**, sonst werden die manuellen Regeln überschrieben.

### Middleware aus dem Feature-Branch installieren

Vor dem Upgrade sicherstellen, dass im produktiven `/opt/kienzlefon-t2med` keine unübernommenen lokalen Hotfixes liegen, da der Installer diese Anwendung neu aufspielt. Sicherung und Check:

~~~bash
sudo tar -czf "/root/kienzlefon-t2med-vor-queue-$(date +%Y%m%d-%H%M%S).tgz" \
  /opt/kienzlefon-t2med /etc/kienzlefon-t2med
git clone --single-branch --branch feature/dynamic-queue-participation \
  https://github.com/philvdb/kienzlefon-t2med.git /root/kienzlefon-t2med-queue
cd /root/kienzlefon-t2med-queue
sudo bash installer/install.sh --dry-run
sudo bash installer/install.sh --keep-config --skip-samba
~~~

**Achtung:** Der Installer beendet/ersetzt/startet den Middleware-Dienst; der Funktionsumfang lokaler unversionierter Anpassungen muss vorab gegen den Branch geprüft sein. `--skip-samba` lässt die vorhandene GDT-Freigabe unverändert.

In `/etc/kienzlefon-t2med/config.toml` den vorhandenen `[telephony]`-Schlüssel `queues = ["praxis", "kienzlefon-sonder"]` prüfen/setzen und **im bestehenden** `[telephony.extensions]` die folgenden sieben Schlüssel konsistent verwenden (nicht zusätzlich einen zweiten TOML-Abschnitt anlegen):

~~~toml
[telephony.extensions]
"201" = "Backoffice"
"202" = "Zimmer 6"
"203" = "Tresen rechts"
"204" = "Tresen links"
"301" = "Sprechzimmer 1"
"302" = "Sprechzimmer 2"
"303" = "Sprechzimmer 3"

[queue_control]
enabled = true
state_file = "/var/lib/kienzlefon-t2med/queue-selection.json"
default_practice = ["201", "202", "203", "204"]
default_special = ["201", "202", "203", "204", "301", "302", "303"]
~~~

Vorhandene `[clients.*]`-, GDT-, FHIR-/APS-, Telefonie- und HTTP-Einstellungen unverändert lassen. `[queue_control]` darf nur einmal existieren.

AMI-Recht kontrollieren:

~~~bash
grep -n '^write=' /etc/asterisk/manager_kienzlefon_middleware.conf
~~~

Ist dort **exakt** `write=call,originate,reporting` gesetzt, diese Zeile nach Dateisicherung auf `write=call,originate,reporting,agent` erweitern. Andere Werte vor Änderung prüfen, nicht überschreiben. Dann:

~~~bash
asterisk -rx 'manager reload'
sudo -u kienzlefon-t2med php /opt/kienzlefon-t2med/bin/server.php \
  --config=/etc/kienzlefon-t2med/config.toml --check-config
systemctl restart kienzlefon-t2med.service
systemctl is-active kienzlefon-t2med.service
asterisk -rx 'queue show praxis'
asterisk -rx 'queue show kienzlefon-sonder'
~~~

### Interner Test (ohne eingehenden Provideranruf)

Im lokalen Browser `http://192.168.100.105:8766/` öffnen, bei den Nebenstellen die zwei Schalter **Normal** und **IVR 9** prüfen:

1. `202` nur in **Normal** ausschalten: nur der Eintrag in `queue show praxis` muss `paused` anzeigen; die Sonderqueue bleibt unverändert.
2. `303` nur in **IVR 9** ausschalten: nur `queue show kienzlefon-sonder` zeigt `paused`.
3. `301` in **Normal** einschalten: dynamischer `Local/301@kienzlefon-queue-member`-Eintrag mit Penalty 3 erscheint. Ausschalten sollte ihn pausieren.
4. Middleware-Dienst neu starten und prüfen, dass die Schalter aus `/var/lib/kienzlefon-t2med/queue-selection.json` wiederhergestellt werden. Danach `module reload app_queue.so` testen, damit auch Wiederherstellung nach Queue-Reload geprüft wird.
5. Vor dem Ende des Tests die gewünschten Standardzustände wiederherstellen und die letzte aktive Nebenstelle testweise auszuschalten versuchen; das muss abgelehnt werden.

**Noch offener Abnahmepunkt:** Die neue Max-Penalty-Grenze verzögert bei deaktivierter 201 derzeit die erste verfügbare höhere Stufe möglicherweise um 20 Sekunden. Die dynamische Staffelung muss später so ergänzt werden, dass die erste tatsächlich aktive Nebenstelle sofort klingelt. Bis dahin die 201 für den regulären Betrieb aktiviert lassen. Die externe Rufreihenfolge wurde mit dem manuellen Fix erfolgreich getestet; nach Installation des Kienzlefon-Kernpatches oder jeder Neugenerierung erneut prüfen.

### Rückweg

Bei Problemen `enabled = false` in `[queue_control]` setzen, den Dienst neu starten und die benötigten Mitglieder manuell mit `queue unpause member ... queue <name>` aktivieren. Die Checkboxen werden dann ausgeblendet. Bei Fehlern nach einem Installer-Upgrade die vorab angelegte Sicherung der Middleware-/Config-Dateien zur Wiederherstellung verwenden.

## Erster produktiver AMI-Funktionstest (15.11.2026, 01:23 CET)

- Benutzerseitig bestätigt: `php tests/php/run.php` nach Korrektur des fehlenden `Telephony`-Imports ohne Fehler; Feature-Branch-Middleware erfolgreich installiert.
- `--check-config` ergibt `Konfiguration gültig.`, `kienzlefon-t2med.service` läuft nach Neustart.
- **Checkbox IVR 9 für 303 ausgeschaltet:** `asterisk -rx 'queue show kienzlefon-sonder'` bestätigt `Telefon 303 ... (paused was 15 secs ago)`; die übrigen sechs Mitglieder sind weiterhin `Not in use` und nicht pausiert. Die Live-AMI-Ansteuerung funktioniert.
- Fonial wieder erreichbar; korrigierte progressive Rufstaffelung 201 → 202 → 203 → 204 wurde bei einem externen Testanruf bestätigt.
- **Vom Betreiber als erfolgreich bestätigt:** Middleware-Neustart mit gespeichertem Zustand, unabhängiges Ausschalten der 202 in der Praxisqueue und dynamisches Hinzufügen/Deaktivieren von 301 in der Praxisqueue. Die initiale Deaktivierung von 303 in der Sonderqueue war bereits im Asterisk-Status nachgewiesen.
- **Erfolgreich vom Betreiber bestätigt:** auch vollständiger Asterisk-Neustart einschließlich Rückkehr der gewünschten Queue-Mitgliedschaften/Pausierungen.
- **Entscheidung vom 15.11.2026:** Backoffice 201 soll im Praxisbetrieb dauerhaft zugeschaltet bleiben. **Keine dynamische Verdichtung/Neuvergabe der Ruf-Penalties** für eine absichtliche Abschaltung der 201; eine hierbei entstehende Verzögerung um bis zu eine Rufstufe wird akzeptiert. Eine optionale, einfache UI-/API-Sperre für das Abschalten von 201 ist noch nicht implementiert.
- **Noch offen:** Parallelanrufe/FIFO, gesonderter 24/7-IVR-Test und gegebenenfalls UI-/API-Sperre für 201.
- Kein erneuter Kienzlefon-Generatorlauf bis zur dauerhaften Integration der manuell getesteten `QUEUE_MAX_PENALTY`-Änderung.

### Kontrollierter Asterisk-Neustart nach Checkbox-Test (geschlossener Praxisbetrieb; bereits erfolgreich durchgeführt)

Die bisherige Middleware-Neustartprüfung beweist noch nicht, dass ein **verlorener Asterisk-Queue-Zustand** wiederhergestellt wird. Dafür im wartungsfreien Zustand zwei unterschiedliche Marker setzen: die bereits statische 303 in `kienzlefon-sonder` pausieren sowie 301 dynamisch in `praxis` aktivieren. Dann sicherstellen, dass keine Gespräche bestehen:

~~~bash
asterisk -rx 'core show channels count'
asterisk -rx 'queue show praxis'
asterisk -rx 'queue show kienzlefon-sonder'
~~~

**Nur wenn keine aktiven Gespräche vorhanden sind und der Testabbruch akzeptabel ist:** `systemctl restart asterisk` (nicht bloß `module reload app_queue.so`) führt zu einem echten Neustart der Queue-In-Memory-Daten. Die externen SIP-Trunks sind währenddessen kurz unterbrochen; nach Neustart Registrierungen prüfen. Die T2med-Middleware verbindet sich per AMI neu und führt nach dem Snapshot `QueueControl::restore()` aus. Bis dahin können 301 und 303 kurz ihren gewünschten Zustand verlieren.

~~~bash
systemctl restart asterisk
systemctl is-active asterisk
asterisk -rx 'pjsip show endpoints'
asterisk -rx 'queue show praxis'
asterisk -rx 'queue show kienzlefon-sonder'
journalctl -u kienzlefon-t2med.service -n 30 --no-pager
~~~

**Erwartung nach AMI-Wiederverbindung:** 301 ist in der Praxisqueue dynamisch ergänzt und 303 in `kienzlefon-sonder` pausiert. Wenn nicht, den T2med-Dienst einmal neu starten, danach erneut prüfen und den selbständigen Wiederanlauf als Defekt festhalten. Es reicht **nicht**, dass die Checkboxen im Browser den gespeicherten Wunschzustand anzeigen; der tatsächliche Asterisk-Status muss dazu passen.

Anschließend 301 in `praxis` und 303 in der Sonderqueue zurück auf die normale Anfangsbelegung setzen. Keine Konfiguration mit `kienzlefon-asterisk` neu generieren, bis der Kernpatch eingebaut ist; sonst verschwindet der manuelle Max-Penalty-Fix.

## Abnahme vor Produktivbetrieb

1. MFA-Nebenstellen 201–204 und Sprechzimmer 301–303 sind registriert und intern getestet. Die ursprüngliche falsche Reihenfolge wurde mit dem **manuellen** `QUEUE_MAX_PENALTY`-Fix bei einem externen Testanruf erfolgreich korrigiert. Nach Übernahme des dauerhaften Kernpatches erneut prüfen.
2. Vier Wartende und nur 201 aktiv: 202 und 203 während der Warteschlange zuschalten. Der älteste Anrufer muss als erster angenommen werden; der nächste erst danach.
3. Schalter Normal und IVR 9 unabhängig testen; bei laufenden Gesprächen pausieren und wieder aktivieren.
4. Zwei Browser versuchen, die jeweils letzte aktive Nebenstelle auszuschalten: Der Server muss mindestens eine aktiv lassen.
5. Asterisk und Middleware nach verschiedenen Einstellungen neu starten und tatsächliche Queue-Mitgliedschaft sowie Kontrollkästchen abgleichen.
6. AMI-Verbindung ausfallen lassen: keine neuen Aktionen und keine falschen Erfolgsmeldungen.
7. IVR 9 außerhalb der Sprechzeiten mit [ivr].fachstellen_immer_erreichbar=true einschließlich Ansage prüfen.
8. Nach Kienzlefon-Upstream-Updates sicherstellen, dass der Kernpatch weiter angewendet wird oder upstream übernommen wurde.

Nicht Bestandteil: Handyweiterleitung, Zimmer-6-Zeitplanung oder automatische Änderung der laufenden Praxisinstallation.
