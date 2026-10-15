# Metzler VDM10 für Symcon

Bindet die Video-Türstation **Metzler VDM10 (2.0)** lokal über ISAPI in Symcon ein –
ohne Hik-Connect-Cloud. Die VDM10 basiert auf der Hikvision-Modulartürstation
DS-KD8003(B); geprüft mit Firmware V3.7.1 (build 251112).

## Funktionen

- **Klingeln** in Echtzeit über den ISAPI-Alert-Stream (dauerhafte Verbindung per Client Socket),
  Rückfallebene: Abfrage des Rufstatus im Sekundentakt
- Rufstatus (Frei / Klingelt / Gespräch), Anruf annehmen, ablehnen, auflegen
- **Türöffner 1 und 2** (Relais der Türstation), Rückmeldung „Schloss geöffnet"
- **Zutrittsereignisse** mit Personenname: Fingerabdruck, Karte, PIN, abgelehnte Versuche
- Bewegung, Sabotage, Türkontakt (sofern an der Türstation konfiguriert)
- **Snapshot** beim Klingeln und auf Knopfdruck, mit Archiv der letzten Bilder
- **Livebild** als Symcon-Medienobjekt (RTSP, H.264) für Kachel-Visualisierung und App
- **Personenverwaltung** im Konfigurationsformular und per Skript: Personen anlegen, umbenennen,
  löschen; PIN setzen oder entfernen; Gültigkeitszeitraum (z. B. für Gäste);
  **Fingerabdruck und Karte am Leser der Türstation anlernen** und wieder löschen
- Neustart der Türstation, generischer ISAPI-Durchgriff `VDM10_Request()` für Skripte

Gegensprechen (Audio) übernimmt dieses Modul nicht. Dafür die Türstation per
Standard-SIP am Symcon-Modul „Türsprechanlage" (DoorIP) oder an einer FRITZ!Box anmelden
(Türstation: System und Wartung → Netzwerk → Gerätezugriff → VoIP).

## Voraussetzungen an der Türstation

- HTTP aktiv (Standard, Port 80); Benutzer `admin` mit dem bei der Aktivierung gesetzten Passwort
- Das admin-Passwort sollte keine Zeichen enthalten, die in einer URL eine Bedeutung haben
  (für den RTSP-Livestream empfiehlt Metzler nur Buchstaben, Ziffern und `-_~`)
- Symcon muss die Türstation im LAN erreichen (Port 80 für ISAPI, Port 554 für RTSP)

## Einrichtung

1. Instanz **Metzler VDM10 Türstation** anlegen. Das Modul legt automatisch einen Client Socket
   als übergeordnete Instanz an.
2. IP-Adresse und admin-Passwort eintragen, „Verbindung testen".
3. Optional: Anzahl Türöffner, Snapshot-Archiv, Stream-Kanal anpassen.

## Variablen

| Ident | Inhalt |
|---|---|
| `CALL_STATUS` | 0 Frei, 1 Klingelt, 2 Gespräch |
| `DOORBELL` | true während des Klingelns (Rücksetzung nach „Klingel zurücksetzen nach") |
| `LAST_RING` | Zeitpunkt des letzten Klingelns |
| `DOOR1`, `DOOR2` | Türöffner (Schalten auf true öffnet, springt nach 3 s zurück) |
| `CALL_CONTROL` | 1 Annehmen, 2 Ablehnen, 3 Auflegen |
| `MOTION`, `TAMPER` | Bewegung, Sabotage |
| `LAST_ACCESS`, `LAST_ACCESS_TIME` | letzter Zutritt (Art, Name) und Zeitpunkt |
| `LAST_EVENT` | letztes Ereignis im Klartext mit Rohcode |
| `ONLINE`, `STREAM_CONNECTED` | Türstation erreichbar, Alert-Stream verbunden |

Medienobjekte: `SNAPSHOT` (aktuelles Bild), `SNAPSHOT_1..n` (Archiv), `LIVE` (RTSP-Stream).

## Funktionen

```php
VDM10_UnlockDoor(int $InstanzID, int $Tuer);          // 1 oder 2
VDM10_TakeSnapshot(int $InstanzID): bool;
VDM10_UpdateCallStatus(int $InstanzID): string;       // idle | ring | onCall
VDM10_AnswerCall / VDM10_RejectCall / VDM10_HangUp(int $InstanzID): bool;
VDM10_Reboot(int $InstanzID): bool;
VDM10_Request(int $InstanzID, string $Methode, string $Pfad, string $Body = ''): string;
VDM10_ReconnectStream(int $InstanzID): void;

VDM10_GetPersons(int $InstanzID): array;
VDM10_AddPerson(int $InstanzID, string $Name, string $Pin): string;   // liefert die Personennummer
VDM10_RenamePerson(int $InstanzID, string $Nr, string $Name): bool;
VDM10_SetPersonPin(int $InstanzID, string $Nr, string $Pin): bool;     // '' entfernt die PIN
VDM10_SetPersonValidity(int $InstanzID, string $Nr, int $Beginn, int $Ende): bool; // 0/0 = unbegrenzt
VDM10_EnrollFingerprint(int $InstanzID, string $Nr, int $Finger): bool; // 0 = nächster freier Platz
VDM10_EnrollCard(int $InstanzID, string $Nr): string;                  // liefert die Kartennummer
VDM10_DeleteFingerprints / VDM10_DeleteCards / VDM10_DeletePerson(int $InstanzID, string $Nr): bool;
```

Beim Anlernen eines Fingerabdrucks wartet die Türstation etwa 10 Sekunden auf den ersten Kontakt.
Der Finger wird mehrmals aufgelegt (nach jedem Piepen kurz abheben). Ein bereits angelernter
Finger wird als Zutrittsversuch gewertet statt aufgenommen. Die Karte wird nach dem Klick einmal
vor den Leser gehalten.

## Technischer Hintergrund

- Ereignisse: `GET /ISAPI/Event/notification/alertStream` (multipart/mixed). Die Türstation erlaubt
  nur **eine** Stream-Verbindung; nach einem Verbindungsabbruch antwortet sie bis zu etwa einer
  Minute mit HTTP 500, bis sie den Slot freigibt. Der Watchdog verbindet dann neu.
- Heartbeat: alle 10 s ein `videoloss/inactive`-Paket; bleibt es aus, wird neu verbunden.
- Klingeltaster = AccessControllerEvent 1/3 (Alarmeingang), einige Sekunden bevor `callStatus`
  auf `ring` wechselt; Fingerabdruck abgelehnt = 5/39, Schloss geöffnet = 5/21.
- HTTP-Listening (`httpHosts`) ist auf Firmware V3.7.1 nicht funktionsfähig: Die Konfiguration
  wird gespeichert (HTTP/HTTPS, IP/Hostname, XML/JSON, mit/ohne Digest), der Test antwortet mit
  „Device Error", und bei echten Ereignissen (Klingeln, Fingerabdruck) baut die Türstation keine
  TCP-Verbindung zum eingetragenen Host auf (am 15.10.2026 mit Paketlogger geprüft). Die
  Bewegungserkennung lässt sich nicht auf `notificationMethod` HTTP umstellen, `subscribeEvent`
  wird nicht unterstützt. Deshalb der Alert-Stream.
- Befehle: `PUT /ISAPI/AccessControl/RemoteControl/door/<n>`, `PUT /ISAPI/VideoIntercom/callSignal`,
  `GET /ISAPI/VideoIntercom/callStatus`, `GET /ISAPI/Streaming/channels/101/picture`,
  `PUT /ISAPI/System/reboot`, Zutrittsereignisse `POST /ISAPI/AccessControl/AcsEvent`.
- Personen: `UserInfo/Record|Modify|Search|Delete`, Fingerabdruck `CaptureFingerPrint` +
  `FingerPrint/SetUp` (Suche `FingerPrintUpload`, Löschen `FingerPrint/Delete`), Karte
  `CaptureCardInfo` + `CardInfo/Record|Delete`. Der Alert-Stream liefert bei Zutritten keinen
  Namen; das Modul holt ihn aus dem Ereignisprotokoll der Türstation.
- Probe-Skript für andere Firmware-Stände: `php tests/probe-isapi.php <IP> admin <Passwort>`.
