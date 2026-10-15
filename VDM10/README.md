# VDM10 (Metzler VDM10 Türstation)

Gerätemodul für die Video-Türstation **Metzler VDM10 (2.0)** über die lokale ISAPI-Schnittstelle
(Hikvision DS-KD8003-Basis). Keine Cloud, kein Hik-Connect.

## Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanz](#4-einrichten-der-instanz)
5. [Statusvariablen und Medien](#5-statusvariablen-und-medien)
6. [Konfigurationsbereiche im Aktionsteil](#6-konfigurationsbereiche-im-aktionsteil)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)
8. [Metzler-Sicherheitsmodul](#8-metzler-sicherheitsmodul)
9. [Kachel in der Visualisierung](#9-kachel-in-der-visualisierung)
10. [Erweiterungsmodule und Klingeltasten](#10-erweiterungsmodule-und-klingeltasten)

## 1. Funktionsumfang

- Klingeln, Bewegung, Sabotage und Zutritte (Fingerabdruck, Karte, PIN) in Echtzeit über den ISAPI-Alert-Stream
- Rufstatus, Anruf annehmen/ablehnen/auflegen
- Türöffner 1 und 2, „Tür dauerhaft offen“
- Snapshot beim Klingeln mit Archiv, RTSP-Livebild als Medienobjekt
- Bewegungserkennung, Empfindlichkeit, Lautstärken, Klingelton, Tastenbeleuchtung schaltbar
- Personen, Fingerabdrücke, Karten, PIN, Gültigkeit und Zugangszeitpläne verwalten
- Öffnungsdauer, Ausgang 2, Eingänge 1–4 und Fingerabdruck-Sicherheitsstufe einstellen
- Eigene Kachel als Steuerzentrale mit Ereignis-Log, letzten Zutritten und Personenfotos
- Erweiterungsmodule (Touch-Display, Namensschild-, Taster-, Tastenfeld-, Info-Module, Finger- und Kartenleser): Status, Display-Einstellungen, Klingeltasten

Gegensprechen (Audio) übernimmt das Symcon-Modul „Türsprechanlage“ (SIP) oder eine FRITZ!Box.

## 2. Voraussetzungen

- Symcon ab Version 8.2
- Metzler VDM10 (2.0) im lokalen Netz, HTTP aktiv (Port 80), RTSP (Port 554)
- Benutzer `admin` mit dem bei der Aktivierung vergebenen Passwort
- Für das Livebild: Passwort ohne Zeichen, die in einer URL eine Bedeutung haben

## 3. Software-Installation

Über den Module Store oder im Module Control die Bibliothek „Metzler VDM10“ hinzufügen.

## 4. Einrichten der Instanz

Unter „Instanz hinzufügen“ die Instanz **Metzler VDM10 Türstation** anlegen. Die Konsole legt dabei
einen **Client Socket** an, über den der Ereignis-Stream läuft. Host und Port übernimmt der Socket aus
der Türstation-Instanz.

| Eigenschaft | Beschreibung |
|---|---|
| IP-Adresse / Hostname, HTTP-Port, HTTPS | Adresse der Türstation |
| Benutzername, Passwort | Zugang zur Türstation (Digest) |
| Ereignisse über den ISAPI-Alert-Stream | Echtzeit-Ereignisse über den Client Socket |
| Rufstatus abfragen alle | Rückfallebene ohne Stream (0 = aus) |
| Klingel / Bewegung zurücksetzen nach | Wie lange die Variablen aktiv bleiben |
| Türöffner | 1 oder 2 (zweiter Türöffner an Ausgang 2) |
| Metzler-Sicherheitsmodul installiert | Legt die Variable „Sicherheitsmodul“ (online/offline) an und zeigt den Sabotage-Schalter |
| Türkontakt angeschlossen | Legt je Tür „Tür n“ (offen/geschlossen) und „Tür n Alarm“ (zu lange oder gewaltsam geöffnet) an |
| Snapshot beim Klingeln, Archiv, Bildquelle | Bilder beim Klingeln |
| RTSP-Livebild, Livebild-Quelle | Medienobjekt mit dem Videostream |

Bricht die Verbindung ab, öffnet Symcon den Client Socket nach spätestens 60 Sekunden neu; die
Türstation gibt einen abgebrochenen Stream erst nach etwa einer Minute frei. In dieser Zeit liefert die
Rückfallebene Klingeln und Zutritte mit einigen Sekunden Verzögerung.

## 5. Statusvariablen und Medien

| Ident | Typ | Beschreibung |
|---|---|---|
| `CALL_STATUS` | Integer | 0 Frei, 1 Klingelt, 2 Gespräch |
| `DOORBELL`, `LAST_RING` | Boolean, Integer | Klingeln und Zeitpunkt |
| `DOOR1`, `DOOR2` | Boolean | Türöffner (Impuls) |
| `DOOR1_HOLD`, `DOOR2_HOLD` | Boolean | Tür dauerhaft offen |
| `CALL_CONTROL` | Integer | Annehmen, Ablehnen, Auflegen |
| `MOTION`, `TAMPER` | Boolean | Bewegung, Sabotage |
| `LAST_ACCESS`, `LAST_ACCESS_TIME` | String, Integer | Letzter Zutritt mit Name |
| `LAST_EVENT` | String | Letztes Ereignis im Klartext |
| `ONLINE`, `STREAM_CONNECTED`, `SIP_STATUS` | | Erreichbarkeit, Stream, SIP-Anmeldung |
| `MOTION_DETECTION`, `MOTION_SENSITIVITY` | Boolean, Integer | Bewegungserkennung, Stufe 1–6 |
| `RING_ENABLED`, `RING_VOLUME`, `TALK_VOLUME` | Boolean, Integer | Klingelton, Lautstärken 0–10 |
| `BACKLIGHT` | String | Tastenbeleuchtung: auto, on, off, custom |
| `FIRMWARE` | String | Firmware der Türstation |
| `SECURITY_MODULE` | Boolean | Metzler-Sicherheitsmodul online (nur wenn installiert) |
| `DOOR1_CONTACT`, `DOOR2_CONTACT` | Boolean | Türkontakt: true = offen (nur mit Türkontakt) |
| `DOOR1_ALARM`, `DOOR2_ALARM` | Boolean | Tür zu lange oder gewaltsam geöffnet |

Medien: `SNAPSHOT`, `SNAPSHOT_1` … `SNAPSHOT_n` (Archiv), `LIVE` (RTSP).

## 6. Konfigurationsbereiche im Aktionsteil

- **Personen, Fingerabdrücke und Karten:** Liste der Personen; „+“ legt an, das Stift-Symbol öffnet den
  Dialog mit Name, PIN (nur mit Tastenfeld-Modul), Gültigkeit, Zugangszeitplan und den Buttons zum
  Anlernen und Löschen.
- **Zugangszeitpläne:** Zeitfenster je Tagesgruppe; gelten für alle Türen der Person.
- **Einstellungen der Türstation:** Öffnungsdauer je Tür, Ausgang 2, Eingänge 1–4,
  Fingerabdruck-Sicherheitsstufe, Sabotagealarm des Sicherheitsmoduls (nur wenn installiert). „In die Türstation schreiben“ überträgt nur geänderte Werte; manche
  Änderungen (z. B. Ausgang 2) wirken erst nach einem Neustart der Türstation.

Alle Änderungen gehen sofort an die Türstation.

## 7. PHP-Befehlsreferenz

```php
VDM10_UnlockDoor(int $InstanzID, int $Tuer): bool;
VDM10_TakeSnapshot(int $InstanzID): bool;
VDM10_UpdateCallStatus(int $InstanzID): string;            // idle | ring | onCall
VDM10_AnswerCall / VDM10_RejectCall / VDM10_HangUp(int $InstanzID): bool;
VDM10_Reboot(int $InstanzID): bool;
VDM10_RefreshStatus(int $InstanzID): void;
VDM10_ReconnectStream(int $InstanzID): void;
VDM10_Request(int $InstanzID, string $Methode, string $Pfad, string $Body = ''): string;

VDM10_GetPersons(int $InstanzID): array;
VDM10_AddPerson(int $InstanzID, string $Name, string $Pin): string;
VDM10_RenamePerson(int $InstanzID, string $Nr, string $Name): bool;
VDM10_SetPersonPin(int $InstanzID, string $Nr, string $Pin): bool;
VDM10_SetPersonValidity(int $InstanzID, string $Nr, int $Beginn, int $Ende): bool;
VDM10_SetPersonSchedule(int $InstanzID, string $Nr, int $Zeitplan): bool;
VDM10_EnrollFingerprint(int $InstanzID, string $Nr, int $Finger): bool;
VDM10_EnrollCard(int $InstanzID, string $Nr): string;
VDM10_DeleteFingerprints / VDM10_DeleteCards / VDM10_DeletePerson(int $InstanzID, string $Nr): bool;

VDM10_GetSchedules(int $InstanzID): array;
VDM10_SetSchedule(int $InstanzID, int $Nr, string $Name, string $Zeitfenster): int;
VDM10_DeleteSchedule(int $InstanzID, int $Nr): bool;
```

Schaltbare Variablen lassen sich zusätzlich mit `RequestAction` setzen.

## 8. Metzler-Sicherheitsmodul

Mit dem Sicherheitsmodul (Metzler-SM) hängt der Türöffner nicht mehr an der Türstation, sondern am
Modul im geschützten Innenbereich. Das Modul ist per RS-485 an die Türstation angeschlossen und bindet
sich beim ersten Start fest an deren Seriennummer. Türöffnen, „dauerhaft offen“ und die Öffnungsdauer
funktionieren unverändert, die Türstation leitet die Befehle weiter (DIP-Schalter ID 1 = Relais 1).
Am Sicherheitsmodul lassen sich Türkontakt und Exit-Taster anschließen. Die Zuordnung der Ereignisse
ist geprüft (`tests/SecurityModuleEventsTest.php`), ein Test mit angeschlossenem Modul steht aus.

## 9. Kachel in der Visualisierung

Die Instanz bringt eine eigene HTML-Kachel mit. Sie passt sich der Kachelgröße an:

- **Klein:** Status, Türöffner und letztes Klingeln. Während es klingelt, stehen „Ablehnen“ und
  „Tür öffnen“ im Vordergrund.
- **Normal:** zusätzlich der letzte Snapshot mit den Knöpfen „Livebild“ (öffnet das Medienobjekt
  `LIVE`) und „Neues Bild“, je Tür ein Türöffner und der Schalter „Dauerhaft offen“, Schnellschalter
  für Klingelton und Bewegungserkennung, die letzten Zutritte und das Ereignis-Log.
- **Breit** (z. B. maximiert): zwei Spalten, links Bild und Steuerung, rechts das Log.

Der Türöffner reagiert erst, wenn er eine Sekunde gedrückt gehalten wird. So öffnet ein
versehentliches Antippen keine Tür.

**Letzte Zutritte** zeigt die fünf zuletzt eingetretenen Personen mit Foto, Art des Zutritts und
Zeit. Abgelehnte Versuche erscheinen als „Unbekannt“ mit rotem Rand. Mehrere Fehlversuche
hintereinander bilden einen Eintrag, ein roter Badge am Foto zeigt ihre Anzahl. Das Foto wählt man im
Personen-Dialog unter „Foto“ aus den Bild-Medienobjekten von Symcon. Die Türstation selbst kann keine
Fotos speichern.

Der Knopf **Bilder** auf dem Snapshot öffnet eine Galerie mit dem aktuellen Bild und den Bildern der
letzten zehn Klingel-Ereignisse. Ein Tipp öffnet die Großansicht. Dort blättern die Pfeile, eine
Wischgeste oder die Pfeiltasten durch die Bilder.

Das **Ereignis-Log** hält die letzten 50 Ereignisse und übersteht Neustarts: Klingeln, Türöffner,
dauerhaft offen, Zutritte, Bewegung, Sabotage, Türkontakt, Verbindung und SIP-Anmeldung. Die letzten
zehn Klingel-Einträge haben ein Vorschaubild. Ein Tipp darauf zeigt das Bild groß. Die Bilder liegen
unter `media/vdm10_<InstanzID>/` und werden beim Löschen der Instanz entfernt.

Bilder lädt die Kachel über den WebHook `/hook/vdm10/<InstanzID>`. Jede Adresse trägt ein zufälliges
Token der Instanz; ohne gültiges Token antwortet der Hook mit 403. Ausgeliefert werden nur der
aktuelle Snapshot, Klingelbilder aus dem Log und zugeordnete Personenfotos. Das Token kennt jeder
Client, der die Kachel anzeigt. Es schützt vor Abrufen ohne Visualisierung, zum Beispiel aus dem
LAN. Prüfung ohne Symcon: `php tests/ImageHookTest.php`.

## 10. Erweiterungsmodule und Klingeltasten

Das Modul liest die Erweiterungsmodule am RS-485-Bus der Türstation, zum Beispiel das Touch-Display
VDM10-TDM, Namensschild- und Taster-Module, das Tastenfeld, das Info-Modul und den Finger- und
Kartenleser.

- **Status:** Je Modul gibt es eine Variable „Online/Offline“. Fällt ein Modul aus, steht das im
  Ereignis-Log, und die Kachel zeigt den Chip „Module“ rot. Der Status wird jede Minute gelesen.
- **Display-Module:** Im Aktionsbereich unter „Erweiterungsmodule und Klingeltasten“ öffnet das
  Bearbeiten-Symbol die Einstellungen: Helligkeit, Tastenton, Design, Startbildschirm, direktes
  Klingeln und die Taste „Zentrale anrufen“. Es erscheinen nur Felder, die das Modul meldet. Die
  Helligkeit gibt es zusätzlich als Schieberegler-Variable, etwa um das Display nachts zu dimmen.
- **Klingeltasten:** Die Liste zeigt jede Taste mit der Zimmernummer, die sie ruft. Zimmernummer und
  Beschriftung lassen sich ändern. Beschriftungen speichert die Türstation nur für Tasten eines
  Touch-Displays.
- **Welche Taste geklingelt hat:** Ab zwei Tasten zeigt die Variable „Klingeltaste“ die zuletzt
  gedrückte Taste, und das Log nennt sie beim Klingeln. Das funktioniert nur, wenn die Türstation die
  Taste im Ereignis mitschickt (Felder `buttonNo`, `keyNo`, `callButtonNo`, `callNumber`,
  `roomNumber`) oder in `callerInfo` einträgt. Die VDM10 mit Firmware V3.7.1 tut beim Taster des
  Hauptmoduls beides nicht: Das Ereignis enthält keine Nummer, und `callerInfo` meldet beim Klingeln
  nur `status: ring` mit `devNo: 0` (gemessen am 15.10.2026).

**Optionale Leser:** Fingerabdruck, Karte und PIN erscheinen nur, wenn ein passendes Modul am Bus
hängt. Fingerabdruck liefert nur der Finger- und Kartenleser (Metzler-FP-RFID). Karten lesen auch das
Touch-Display VDM10-TDM, die Kartenleser-Module und ein Wiegand-Leser am Sicherheitsmodul. Die PIN
setzt ein Tastenfeld oder ein Touch-Display voraus. Fehlt ein Leser, blendet das Formular die
zugehörigen Spalten, Buttons und die Fingerabdruck-Sicherheitsstufe aus. Ohne jeden Leser zeigt die
Kachel keinen Bereich „Letzte Zutritte“. Solange die Modulliste nie gelesen werden konnte, bleibt
alles sichtbar.

Geprüft ist der Finger- und Kartenleser DS-KD-MFB. Display-, Taster- und Info-Module sind nach der
Weboberfläche der Türstation umgesetzt und ohne Hardware getestet (`php tests/SubModulesTest.php`).
Die Option „Variablen für Erweiterungsmodule“ in der Konfiguration schaltet die Variablen ab.
