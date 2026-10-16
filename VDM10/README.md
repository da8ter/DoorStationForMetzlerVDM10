# Türstation für Metzler VDM10

Bindet die Video-Türstation **Metzler VDM10 (2.0)** lokal über die ISAPI-Schnittstelle in Symcon ein, ohne Hik-Connect-Cloud. Die VDM10 basiert auf der Hikvision-Modulartürstation DS-KD8003; geprüft mit Firmware V3.7.1 (build 251112). Die Instanz bringt eine eigene Kachel als Steuerzentrale mit; Skripte und Ablaufpläne steuern alles über Variablen und `VDM10_*`-Funktionen.

Gegensprechen (Audio) übernimmt das Modul nicht. Dafür wird das offizielle Symcon-Modul [Türsprechanlage](https://www.symcon.de/de/shop/enterprise/ips-extension-doorip/) benötigt; die Türstation meldet sich dort per SIP an (Türstation: System und Wartung → Netzwerk → Gerätezugriff → VoIP). Alternativ klingelt sie per SIP an einer FRITZ!Box.

## Die Kachel

| Bereich | Inhalt |
|---|---|
| **Kopf** | Name, letztes Klingeln, Status-Chips Online, SIP, Stream und Module |
| **Bild** | letzter Snapshot mit den Knöpfen Bilder (Galerie), Livebild und Neues Bild |
| **Tür** | Türöffner zum Gedrückthalten, Türkontakt, Schalter „Dauerhaft offen“ |
| **Schnellschalter** | Nicht stören, Rufton außen, Bewegungserkennung |
| **Letzte Zutritte** | die fünf zuletzt eingetretenen Personen mit Foto, Fehlversuche mit Zähler |
| **Ereignisse** | die letzten 50 Ereignisse mit Bildern |

### Ansichten

- **Klein:** Status, Türöffner und letztes Klingeln.
- **Normal:** alle Bereiche untereinander.
- **Breit** (z. B. maximiert): zwei Spalten, links Bild und Steuerung, rechts das Ereignis-Log; jede Spalte scrollt für sich.

### Bedienung

- **Türöffner:** öffnet erst nach einer Sekunde Gedrückthalten; ein versehentliches Antippen öffnet keine Tür.
- **Klingeln:** Der Türöffner von Tür 1 wird zum Hinweis „Es klingelt“ (orange, pulsierend) und öffnet weiter beim Gedrückthalten. Rechts daneben steht ein roter Knopf zum Ablehnen. Beide zusammen sind exakt so groß wie der Türöffner.
- **Bilder:** Die Galerie zeigt das aktuelle Bild und die Bilder von Klingeln, Zutritten, Bewegungen und „Neues Bild“ (je die letzten zehn). Ein Tipp öffnet die Großansicht; Pfeile, Wischgeste oder Pfeiltasten blättern.
- **Log und Zutritte:** Ein Tipp auf einen Eintrag mit Bild oder auf eine Person zeigt das Bild groß. Unbekannte zeigen das Zutrittsbild im Kreis; aufeinanderfolgende Fehlversuche bilden einen Eintrag mit rotem Zähler.
- **Livebild:** öffnet das RTSP-Medienobjekt; die Kachel selbst kann RTSP nicht abspielen.
- **Test der Klingel-Anzeige:** `VDM10_TileTestRing($id, true)`, aus mit `false` oder beim Neuladen des Moduls. „Tür öffnen“ öffnet dabei wirklich.

## Voraussetzungen

- Symcon ab 8.2 mit Kachelvisualisierung
- Metzler VDM10 (2.0) im lokalen Netz: HTTP (Port 80) für ISAPI, RTSP (Port 554) für das Livebild
- Benutzer `admin` mit dem bei der Aktivierung vergebenen Passwort; für das Livebild ohne Zeichen, die in einer URL eine Bedeutung haben (Metzler empfiehlt Buchstaben, Ziffern und `-_~`)
- Für Gegensprechen: das offizielle Symcon-Modul [Türsprechanlage](https://www.symcon.de/de/shop/enterprise/ips-extension-doorip/)
- Die Türstation erlaubt nur **eine** Ereignis-Verbindung. Pro Türstation also nur eine Instanz betreiben.

## Klingeln, Bewegung und Zutritte

- **Klingeln** erkennt das Modul am Rufstatus der Türstation (Abfrage im eingestellten Takt, Vorgabe 3 s). Der Ereignis-Stream meldet das Klingeln nicht. Ein Besuch zählt als ein Klingeln, auch wenn die Türstation bis zu einer Minute ruft.
- **Bewegung, Zutritte, Sabotage, Türkontakt, Notruf, Nötigungsalarm** kommen in Echtzeit über den Ereignis-Stream (Client Socket).
- **Bilder:** beim Klingeln ein Snapshot (mit Archiv), bei jedem Zutrittsversuch und bei Bewegung ein Bild direkt von der Kamera, ohne den Snapshot der Kachel zu ersetzen. Alles abschaltbar.
- **Ereignis-Log:** die letzten 50 Ereignisse, übersteht Neustarts. Bilder liegen unter `media/vdm10_<InstanzID>/` und werden beim Löschen der Instanz entfernt.
- **Bilder in der Kachel** kommen über den WebHook `/hook/vdm10/<InstanzID>` mit einem zufälligen Token der Instanz; ohne gültiges Token antwortet er mit 403. Er liefert nur den Snapshot, Bilder aus dem Log und zugeordnete Personenfotos.

## Personen, Karten und Zeitpläne

- **Personen** im Konfigurationsformular: „+“ legt an, das Stift-Symbol öffnet den Dialog mit Name, PIN, Gültigkeitszeitraum (z. B. für Gäste), Zugangszeitplan, Foto für die Kachel und den Knöpfen zum Anlernen und Löschen von Fingerabdrücken und Karten.
- **Anlernen:** Nach dem Klick wartet die Türstation etwa 10 Sekunden. Den Finger mehrmals auflegen (nach jedem Piepen kurz abheben); die Karte einmal vor den Leser halten.
- **Zugangszeitpläne:** eigene Liste mit Zeitfenstern wie „Mo–Fr 08:00–12:00“; gelten für Fingerabdruck, Karte und PIN, nicht für Klingeln und Öffnen per Kachel.
- **Fotos** speichert die Türstation nicht; sie bleiben als Bild-Medienobjekte in Symcon.
- **Nur mit passendem Modul:** Fingerabdruck braucht den Finger- und Kartenleser (Metzler-FP-RFID), Karten auch Touch-Display, Kartenleser-Modul oder einen Wiegand-Leser am Sicherheitsmodul, die PIN ein Tastenfeld oder Touch-Display. Fehlt ein Leser, blenden Formular und Kachel die zugehörigen Teile aus.

## Rufzeiten und „Nicht stören“

- **Rufzeiten:** Bis zu fünf Zeitfenster je Tag, in denen ein Tastendruck ins Haus ruft (Innenstation bzw. per SIP die FRITZ!Box). Außerhalb klingelt es im Haus nicht. Option an ohne Zeitfenster: Die Tasten rufen nie ins Haus.
- **Nicht stören:** Schalter in der Kachel und Variable, etwa für Abwesenheit oder nachts. Die Tasten rufen nie ins Haus, unabhängig von den Rufzeiten; ausgeschaltet gelten wieder die Rufzeiten.
- In beiden Fällen bekommt Symcon das Klingeln mit Bild und Log-Eintrag, und der Rufton außen bleibt. Technisch nutzt das Modul den Anrufplan der Türstation (Rufzeiten: Plan 1, Nicht stören: leerer Plan 2).

## Türöffner und „Dauerhaft offen“

- **Türöffner 1 und 2** schalten die Relais der Türstation, mit dem Metzler-Sicherheitsmodul dessen Relais. Ein zweiter Türöffner liegt an Ausgang 2.
- **Dauerhaft offen** hält das Relais eingeschaltet, bis es wieder ausgeschaltet wird. Ein Arbeitsstrom-Türöffner steht dann ständig unter Strom: nur verwenden, wenn er für 100 % Einschaltdauer freigegeben ist oder eine Tagesentriegelung hat. Am Sicherheitsmodul gilt höchstens 12 V DC und 1 A.
- **Sperre:** Mit der Option „„Dauerhaft offen“ erlauben“ abgewählt sendet das Modul den Befehl auf keinem Weg, auch nicht über RequestAction oder `VDM10_Request()`, selbst kodiert. Die Variable zeigt dann nur noch den Zustand; steht die Tür über die Weboberfläche trotzdem dauerhaft offen, zeigt die Kachel einen orangen Hinweis.

## Metzler-Sicherheitsmodul

Mit dem Sicherheitsmodul (Metzler-SM) hängt der Türöffner am Modul im geschützten Innenbereich, angeschlossen per RS-485. Türöffnen, „dauerhaft offen“ und die Öffnungsdauer funktionieren unverändert (DIP-Schalter ID 1 = Relais 1). Am Modul lassen sich Türkontakt und Exit-Taster anschließen; das Modul hat nur ein Relais. Variablen „Sicherheitsmodul“ und je Tür „Tür n“ und „Tür n Alarm“ entstehen über die Optionen in der Konfiguration.

## Erweiterungsmodule und Klingeltasten

- **Status** je Modul am Bus der Türstation (Touch-Display VDM10-TDM, Namensschild-, Taster-, Tastenfeld-, Info-Module, Finger- und Kartenleser) als Variable, im Log und als Chip „Module“ in der Kachel.
- **Display-Module:** Helligkeit (auch als Variable), Tastenton, Design, Startbildschirm, direktes Klingeln, Taste „Zentrale anrufen“; es erscheinen nur Felder, die das Modul meldet.
- **Hinweis auf dem Touch-Display:** ein kurzer Text (höchstens 45 Zeichen empfohlen), den das Display im Ruhezustand zeigt, etwa „Paket bitte beim Nachbarn abgeben“. Nur sichtbar, wenn ein Display angeschlossen ist. Die Diashow mit eigenen Bildern bleibt in der Weboberfläche der Türstation.
- **Klingeltasten** mit Zimmernummer und Beschriftung (Beschriftungen speichert die Türstation nur für Display-Tasten). Welche Taste geklingelt hat, zeigt die Variable „Klingeltaste“, sofern die Türstation es meldet; die VDM10 mit Firmware V3.7.1 meldet es beim Taster des Hauptmoduls nicht.
- Geprüft mit dem Finger- und Kartenleser DS-KD-MFB; Display-, Taster- und Info-Module sind nach der Weboberfläche der Türstation umgesetzt, ohne Test an echter Hardware.

## Benachrichtigungen und Uhrzeit

- **Push-Nachrichten** über eine Kachel-Visualisierung: beim Klingeln, wahlweise bei abgelehnten Zutritten und bei Störungen (Sabotage, Türalarm, Notruf, Nötigungsalarm, Türstation offline, SIP-Anmeldung verloren, Modul offline; dieselbe Störung höchstens alle fünf Minuten). Bei „Nicht stören“ entfällt die Klingel-Nachricht, sofern nicht anders eingestellt. Ein Tipp öffnet die Kachel; ein Bild kann die Nachricht selbst nicht enthalten. Voraussetzung: gültige Subskription und registrierte Geräte.
- **Uhrzeit:** Die Türstation stellt ihre Uhr per NTP über einen Hostnamen und driftet, wenn sie keine Namen auflösen kann. Das Modul zeigt die Abweichung und stellt die Uhr ab 30 s auf die Symcon-Zeit (höchstens einmal pro Stunde, abschaltbar); der Zeitmodus bleibt NTP.

## Einrichtung

1. Bibliothek über den Module Store oder im Module Control mit `https://github.com/da8ter/DoorStationForMetzlerVDM10` hinzufügen.
2. Instanz **Türstation für Metzler VDM10** anlegen. Die Konsole legt dabei einen Client Socket für den Ereignis-Stream an.
3. IP-Adresse und admin-Passwort eintragen, „Verbindung testen“.
4. Optional: Türöffner, Sicherheitsmodul, Türkontakt, Bilder, Benachrichtigungen einstellen.

Weitere Bereiche im Formular: **Einstellungen der Türstation** (Öffnungsdauer je Tür, Ausgang 2 z. B. als externer Türgong, Eingänge 1–4, Tastenbeleuchtung mit eigenen Zeiten, Fingerabdruck-Sicherheitsstufe, Sabotagealarm des Sicherheitsmoduls). „In die Türstation schreiben“ überträgt nur geänderte Werte; manche Änderungen (z. B. Ausgang 2) wirken erst nach einem Neustart der Türstation.

### Verbindung

- Nicht erreichbar oder falsches Passwort: Abfragen pausieren, das Modul versucht es alle fünf Minuten neu (keine Kontosperre durch Dauerversuche).
- Bricht der Stream ab, öffnet Symcon den Client Socket neu; die Türstation gibt einen abgebrochenen Stream erst nach etwa einer Minute frei. Zutritte kommen in dieser Zeit verzögert über die Rückfallebene, das Klingeln läuft ohnehin über den Rufstatus.

## Variablen

| Variable | Bedeutung |
|---|---|
| Rufstatus | 0 Frei, 1 Klingelt, 2 Gespräch |
| Klingel, Letztes Klingeln | aktiv beim Klingeln (Rücksetzung nach „Klingel zurücksetzen nach“), Zeitpunkt |
| Nicht stören | Klingeltasten rufen nicht ins Haus (schaltbar) |
| Türöffner 1, Türöffner 2 | öffnet die Tür (schaltbar, springt nach 3 s zurück) |
| Tür n dauerhaft offen | Relais dauerhaft an (schaltbar, sofern erlaubt) |
| Tür n, Tür n Alarm | Türkontakt offen/geschlossen, zu lange oder gewaltsam geöffnet (nur mit Türkontakt) |
| Rufsteuerung | 1 Annehmen, 2 Ablehnen, 3 Auflegen (schaltbar) |
| Bewegung, Sabotage | Ereignisse der Türstation |
| Letzter Zutritt, Zeitpunkt letzter Zutritt | Art, Name, Zeit |
| Letztes Ereignis | Klartext mit Rohcode |
| Türstation, Ereignis-Stream, SIP-Anmeldung | Erreichbarkeit, Stream, SIP |
| Sicherheitsmodul | online/offline (nur wenn installiert) |
| Bewegungserkennung, Bewegungsempfindlichkeit | an/aus, Stufe 1–6 (schaltbar) |
| Klingelton an der Türstation, Klingelton-Lautstärke, Sprechlautstärke | Rufton außen an/aus, Lautstärken 0–10 (schaltbar) |
| Tastenbeleuchtung | Automatisch, An, Aus, Eigene Zeiten (schaltbar) |
| Firmware, Uhrzeit-Abweichung der Türstation | Firmware-Stand, Abweichung zu Symcon in Sekunden |
| Erweiterungsmodule | je Modul online/offline, Display-Helligkeit (schaltbar), Klingeltaste, Hinweis auf dem Touch-Display (schaltbar) |

Medien: Bild (aktueller Snapshot), Bild -1 … -n (Archiv), Livebild (RTSP).

## Funktionen

```php
VDM10_UnlockDoor(int $InstanzID, int $Tuer): bool;              // 1 oder 2 (nur eingerichtete Türen)
VDM10_TakeSnapshot(int $InstanzID): bool;
VDM10_UpdateCallStatus(int $InstanzID): string;                 // idle | ring | onCall
VDM10_AnswerCall(int $InstanzID): bool;
VDM10_RejectCall(int $InstanzID): bool;
VDM10_HangUp(int $InstanzID): bool;
VDM10_SetDoNotDisturb(int $InstanzID, bool $Aktiv): bool;
VDM10_GetCallTimes(int $InstanzID): array;
VDM10_SetCallTimes(int $InstanzID, bool $Aktiv, string $Zeitfenster): bool;
VDM10_SetDisplayText(int $InstanzID, string $Text): bool;       // '' blendet aus (nur mit Touch-Display)
VDM10_SyncClock(int $InstanzID): int;                           // Abweichung danach in Sekunden
VDM10_RefreshStatus(int $InstanzID): void;
VDM10_ReconnectStream(int $InstanzID): void;
VDM10_Reboot(int $InstanzID): bool;
VDM10_TestConnection(int $InstanzID): string;
VDM10_Request(int $InstanzID, string $Methode, string $Pfad, string $Body = ''): string; // ISAPI-Rohzugriff
VDM10_TileTestRing(int $InstanzID, bool $Aktiv): void;          // Klingel-Anzeige der Kachel testen

VDM10_GetPersons(int $InstanzID): array;
VDM10_AddPerson(int $InstanzID, string $Name, string $Pin): string;              // liefert die Personennummer
VDM10_RenamePerson(int $InstanzID, string $Nr, string $Name): bool;
VDM10_SetPersonPin(int $InstanzID, string $Nr, string $Pin): bool;                // '' entfernt die PIN
VDM10_SetPersonValidity(int $InstanzID, string $Nr, int $Beginn, int $Ende): bool; // 0/0 unbefristet, Ende 0 = ab Beginn unbefristet
VDM10_SetPersonSchedule(int $InstanzID, string $Nr, int $Zeitplan): bool;         // 0 = rund um die Uhr
VDM10_EnrollFingerprint(int $InstanzID, string $Nr, int $Finger): bool;           // 0 = nächster freier Platz
VDM10_EnrollCard(int $InstanzID, string $Nr): string;                             // liefert die Kartennummer
VDM10_DeleteFingerprints(int $InstanzID, string $Nr): bool;
VDM10_DeleteCards(int $InstanzID, string $Nr): bool;
VDM10_DeletePerson(int $InstanzID, string $Nr): bool;

VDM10_GetSchedules(int $InstanzID): array;
VDM10_SetSchedule(int $InstanzID, int $Nr, string $Name, string $Zeitfenster): int; // Nr 0 = neu
VDM10_DeleteSchedule(int $InstanzID, int $Nr): bool;                                // verweigert, solange zugeordnet
```

Zeitfenster als JSON-Liste, z. B. `[{"days":"weekdays","from":"08:00","to":"12:00"}]` (`days`: `weekdays`, `weekend`, `daily`, `mon` … `sun`; „23:59“ als Ende = bis Mitternacht). Schaltbare Variablen lassen sich zusätzlich mit `RequestAction` setzen.

## Technischer Hintergrund

- **Ereignisse:** `GET /ISAPI/Event/notification/alertStream` (multipart/mixed) über den Client Socket. Nur eine Verbindung gleichzeitig; nach einem Abbruch antwortet die Türstation bis zu einer Minute mit HTTP 500. Heartbeat alle 10 s; bleibt er aus, meldet das Modul den Stream als getrennt und fragt neu an.
- **Klingeln:** nicht im Stream, nur `GET /ISAPI/VideoIntercom/callStatus` (`ring`, ein Ruf dauert bis ~60 s). AccessControllerEvent 1/3 ist die Bewegungserkennung der Kamera, 5/38 Fingerabdruck ok, 5/39 abgelehnt, 5/21 Schloss geöffnet. Nach HCNetSDK: 1/0x404 Sabotage, 1/0x40A Nötigungsalarm, 1/0x1048 Notruf (0x1049 beendet), 2/1039 bzw. 2/1040 Sicherheitsmodul offline bzw. online, 2/1041 Batterie schwach, 5/1281 benutzerdefiniertes Ereignis 2 (kein Klingeln). 5/37 (Klingeln) sieht das Modul vor, die VDM10 sendet es aber nicht.
- **HTTP-Listening (`httpHosts`)** ist auf Firmware V3.7.1 nicht funktionsfähig: gespeichert wird es, Ereignisse schickt die Türstation trotzdem nicht (am 15.10.2026 mit Paketlogger geprüft). Deshalb der Alert-Stream.
- **Personen:** `UserInfo/Record|Modify|Search|Delete`, Fingerabdruck `CaptureFingerPrint` + `FingerPrint/SetUp`, Karte `CaptureCardInfo` + `CardInfo/Record`. Der Stream liefert bei Zutritten keinen Namen; das Modul holt ihn aus dem Ereignisprotokoll (`AcsEvent`).
- **Prüfen ohne Türstation:** `php tests/<Name>Test.php` (Bild-Hook, Sperre „dauerhaft offen“, Gültigkeit, Erweiterungsmodule, Sicherheitsmodul). Probe-Skript für andere Firmware-Stände: `php tests/probe-isapi.php <IP> admin <Passwort>`.

## Marken

Metzler, Hikvision und Hik-Connect sind Marken ihrer jeweiligen Inhaber. Dieses Modul ist kein Produkt von Metzler oder Hikvision.
