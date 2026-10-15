<?php

declare(strict_types=1);

/**
 * Zerlegt den ISAPI-Alert-Stream (multipart/mixed) und bildet Ereignisse der
 * Türstation auf Modul-Idents ab.
 *
 * Beobachtet an der VDM10 (Firmware V3.7.1): Nach dem Verbindungsaufbau spielt
 * die Türstation die zuletzt gespeicherten AccessControllerEvents mit
 * currentEvent=false ab, danach alle 10 s ein "videoloss/inactive"-Paket als
 * Heartbeat; echte Ereignisse kommen mit currentEvent=true.
 */
final class VDM10Events
{
    public const HEARTBEAT_SECONDS = 10;

    /** Ereignisfelder, die eine Klingeltaste (key) oder die gerufene Zimmernummer (room) benennen. */
    private const KEY_FIELDS = [
        'buttonNo' => 'key', 'keyNo' => 'key', 'callButtonNo' => 'key', 'keyID' => 'key',
        'callNumber' => 'room', 'roomNumber' => 'room', 'roomNo' => 'room', 'callRoomNum' => 'room',
    ];

    /** Ereignis-Klassen, die das Modul unterscheidet. */
    public const KIND_RING = 'ring';
    public const KIND_UNLOCK = 'unlock';
    public const KIND_LOCK = 'lock';
    public const KIND_ACCESS_OK = 'access';
    public const KIND_ACCESS_FAIL = 'access_denied';
    public const KIND_DOOR_OPEN = 'door_open';
    public const KIND_DOOR_CLOSED = 'door_closed';
    public const KIND_DOOR_ALARM = 'door_alarm';
    public const KIND_MOTION = 'motion';
    public const KIND_TAMPER = 'tamper';
    public const KIND_HEARTBEAT = 'heartbeat';
    public const KIND_OTHER = 'other';

    /**
     * Zutritts-Ereignisse (majorEventType 5) nach Hikvision-ACS-Tabelle,
     * ergänzt um die an der VDM10 beobachteten Codes.
     *
     * @var array<int, array{0:string,1:string}> minor => [kind, label]
     */
    private const EVENT_MINOR = [
        1    => [self::KIND_ACCESS_OK, 'Card'],
        2    => [self::KIND_ACCESS_OK, 'Card and PIN'],
        3    => [self::KIND_ACCESS_FAIL, 'Card and PIN'],
        4    => [self::KIND_ACCESS_FAIL, 'Card and PIN timeout'],
        6    => [self::KIND_ACCESS_FAIL, 'Card without permission'],
        7    => [self::KIND_ACCESS_FAIL, 'Card outside validity'],
        8    => [self::KIND_ACCESS_FAIL, 'Expired card'],
        9    => [self::KIND_ACCESS_FAIL, 'Unknown card'],
        21   => [self::KIND_UNLOCK, 'Lock opened'],
        22   => [self::KIND_LOCK, 'Lock closed'],
        23   => [self::KIND_OTHER, 'Exit button pressed'],
        24   => [self::KIND_OTHER, 'Exit button released'],
        25   => [self::KIND_DOOR_OPEN, 'Door opened'],
        26   => [self::KIND_DOOR_CLOSED, 'Door closed'],
        27   => [self::KIND_DOOR_ALARM, 'Door opened abnormally'],
        28   => [self::KIND_DOOR_ALARM, 'Door open too long'],
        37   => [self::KIND_RING, 'Doorbell'],
        38   => [self::KIND_ACCESS_OK, 'Fingerprint'],
        39   => [self::KIND_ACCESS_FAIL, 'Fingerprint'],
        75   => [self::KIND_ACCESS_OK, 'Face'],
        76   => [self::KIND_ACCESS_FAIL, 'Face'],
        92   => [self::KIND_ACCESS_OK, 'PIN'],
        1281 => [self::KIND_RING, 'Call'],
    ];

    /**
     * Alarme (majorEventType 1). Minor 3 ist an der VDM10 die Bewegungserkennung der Kamera:
     * kommt beim Annähern (daher kurz vor dem Klingeln), ohne Klingeln, nachts und alle ~8 s,
     * solange Bewegung anhält (am 15.10.2026 belegt). Das Klingeln selbst meldet der Stream
     * nicht; es wird über callStatus=ring erkannt. 0x404/0x40a/0x40f Sabotage.
     *
     * @var array<int, array{0:string,1:string}>
     */
    private const ALARM_MINOR = [
        3      => [self::KIND_MOTION, 'Motion'],
        0x404  => [self::KIND_TAMPER, 'Tamper'],
        0x40A  => [self::KIND_TAMPER, 'Reader tamper'],
        0x40F  => [self::KIND_TAMPER, 'Secure module tamper'],
        0x405  => [self::KIND_OTHER, 'Tamper resolved'],
        0x1048 => [self::KIND_DOOR_ALARM, 'Door open too long'],
        0x1049 => [self::KIND_OTHER, 'Door open too long resolved'],
    ];

    /**
     * Schneidet vollständige Teile aus dem Stream-Puffer und gibt sie zurück.
     * Jeder Teil: ['ctype' => string, 'body' => string]. Unvollständige Reste
     * bleiben im Puffer.
     *
     * @return array<int, array{ctype:string, body:string}>
     */
    public static function extractParts(string &$buffer): array
    {
        $parts = [];
        while (true) {
            if (!preg_match('/Content-Length:\s*(\d+)\r?\n/i', $buffer, $m, PREG_OFFSET_CAPTURE)) {
                break;
            }
            $length = (int) $m[1][0];
            $headEnd = strpos($buffer, "\r\n\r\n", (int) $m[0][1]);
            $sep = 4;
            if ($headEnd === false) {
                $headEnd = strpos($buffer, "\n\n", (int) $m[0][1]);
                $sep = 2;
            }
            if ($headEnd === false) {
                break; // Kopf noch unvollständig
            }
            $bodyStart = $headEnd + $sep;
            if (strlen($buffer) < $bodyStart + $length) {
                break; // Rumpf noch unvollständig
            }
            $head = substr($buffer, 0, $bodyStart);
            $ctype = preg_match_all('/Content-Type:\s*([^;\r\n]+)/i', $head, $cm) ? strtolower(trim(end($cm[1]))) : '';
            $parts[] = ['ctype' => $ctype, 'body' => substr($buffer, $bodyStart, $length)];
            $buffer = substr($buffer, $bodyStart + $length);
        }
        if (strlen($buffer) > 1048576) {
            $buffer = ''; // Schutz: etwas ist aus dem Takt, neu synchronisieren
        }
        return $parts;
    }

    /**
     * Normalisiert ein EventNotificationAlert (JSON oder XML).
     *
     * @return array{kind:string,label:string,major:int,minor:int,current:bool,name:string,door:int,time:string,raw:string,key:int,room:int}|null
     */
    public static function parseNotification(string $body): ?array
    {
        $body = trim($body);
        if ($body === '') {
            return null;
        }
        if (str_starts_with($body, '{')) {
            $json = json_decode($body, true);
            if (!is_array($json)) {
                return null;
            }
            $acs = is_array($json['AccessControllerEvent'] ?? null) ? $json['AccessControllerEvent'] : [];
            return self::normalize(
                (string) ($json['eventType'] ?? ''), (string) ($json['eventState'] ?? ''),
                (int) ($acs['majorEventType'] ?? 0), (int) ($acs['subEventType'] ?? 0),
                !isset($acs['currentEvent']) || (bool) $acs['currentEvent'],
                (string) ($acs['name'] ?? ''), (int) ($acs['doorNo'] ?? 0), (string) ($json['dateTime'] ?? ''),
                self::buttonInfo($json)
            );
        }
        if (str_starts_with($body, '<')) {
            $get = static fn (string $tag): string => preg_match('/<' . $tag . '>([^<]*)</', $body, $m) ? $m[1] : '';
            $button = ['key' => 0, 'room' => 0];
            foreach (self::KEY_FIELDS as $field => $what) {
                if ($button[$what] === 0 && (int) $get($field) > 0) {
                    $button[$what] = (int) $get($field);
                }
            }
            return self::normalize($get('eventType'), $get('eventState'), (int) $get('majorEventType'),
                (int) $get('subEventType'), $get('currentEvent') !== 'false', $get('name'), (int) $get('doorNo'), $get('dateTime'), $button);
        }
        return null;
    }

    /** @return array{kind:string,label:string,major:int,minor:int,current:bool,name:string,door:int,time:string,raw:string,key:int,room:int} */
    public static function normalize(string $type, string $state, int $major, int $minor, bool $current,
        string $name, int $door, string $time, array $button = []): array
    {
        [$kind, $label] = self::classify($type, $state, $major, $minor);
        return [
            'kind' => $kind, 'label' => $label, 'major' => $major, 'minor' => $minor, 'current' => $current,
            'name' => $name, 'door' => $door, 'time' => $time,
            'raw' => $type === 'AccessControllerEvent' ? $major . '/' . $minor : $type . '/' . $state,
            'key' => (int) ($button['key'] ?? 0), 'room' => (int) ($button['room'] ?? 0),
        ];
    }

    /**
     * Welche Klingeltaste gedrückt wurde, sofern die Türstation es mitschickt. Die VDM10 mit
     * Firmware V3.7.1 meldet das Klingeln im Stream gar nicht (nur callStatus); mit
     * Tasten- oder Touch-Modulen sind diese Felder aus anderen Hikvision-Integrationen bekannt.
     *
     * @return array{key:int,room:int}
     */
    public static function buttonInfo(array $json): array
    {
        $found = ['key' => 0, 'room' => 0];
        $scan = static function (array $node, int $depth) use (&$scan, &$found): void {
            foreach (self::KEY_FIELDS as $field => $what) {
                if ($found[$what] === 0 && isset($node[$field]) && is_numeric($node[$field]) && (int) $node[$field] > 0) {
                    $found[$what] = (int) $node[$field];
                }
            }
            if ($depth < 2) {
                foreach ($node as $child) {
                    if (is_array($child)) {
                        $scan($child, $depth + 1);
                    }
                }
            }
        };
        $scan($json, 0);
        return $found;
    }

    /** @return array{0:string,1:string} */
    public static function classify(string $type, string $state, int $major, int $minor): array
    {
        switch ($type) {
            case 'videoloss':
            case 'heartbeat':
                return [self::KIND_HEARTBEAT, 'Heartbeat'];
            case 'VMD':
            case 'PIR':
            case 'linedetection':
            case 'fielddetection':
                return [$state === 'inactive' ? self::KIND_OTHER : self::KIND_MOTION, 'Motion'];
            case 'shelteralarm':
            case 'tamperdetection':
                return [self::KIND_TAMPER, 'Tamper'];
            case 'AccessControllerEvent':
                if ($major === 5 && isset(self::EVENT_MINOR[$minor])) {
                    return self::EVENT_MINOR[$minor];
                }
                if ($major === 1 && isset(self::ALARM_MINOR[$minor])) {
                    return self::ALARM_MINOR[$minor];
                }
                return [self::KIND_OTHER, 'Event ' . $major . '/' . $minor];
        }
        return [self::KIND_OTHER, $type !== '' ? $type : 'unknown'];
    }

    /** Zutrittsart zu einem Zutritts-Ereignis (majorEventType 5): finger, card, face, pin oder ''. */
    public static function accessMethod(int $minor): string
    {
        return match (true) {
            in_array($minor, [38, 39], true)           => 'finger',
            in_array($minor, [75, 76], true)           => 'face',
            $minor === 92                              => 'pin',
            in_array($minor, [1, 2, 3, 4, 6, 7, 8, 9], true) => 'card',
            default                                    => '',
        };
    }

    /** Ereignis aus der AcsEvent-Suche (JSON-InfoList) in dieselbe Form bringen. */
    public static function fromAcsEvent(array $info): array
    {
        return self::normalize('AccessControllerEvent', 'active', (int) ($info['major'] ?? 0), (int) ($info['minor'] ?? 0),
            true, (string) ($info['name'] ?? ''), (int) ($info['doorNo'] ?? 0), (string) ($info['time'] ?? ''), self::buttonInfo($info));
    }
}
