<?php

declare(strict_types=1);

/**
 * Schlanker ISAPI-Client für Hikvision-basierte Türstationen (Metzler VDM10).
 *
 * Alle Aufrufe laufen über curl mit Digest-Auth. Zusätzlich liefert die Klasse
 * die Bausteine für den Alert-Stream über einen Symcon Client Socket: eine
 * frische Digest-Challenge (nonce) und den fertigen Authorization-Header.
 */
final class VDM10Isapi
{
    public const PATH_DEVICE_INFO = '/ISAPI/System/deviceInfo';
    public const PATH_CALL_STATUS = '/ISAPI/VideoIntercom/callStatus?format=json';
    public const PATH_CALL_SIGNAL = '/ISAPI/VideoIntercom/callSignal?format=json';
    public const PATH_DOOR = '/ISAPI/AccessControl/RemoteControl/door/';
    public const PATH_PICTURE = '/ISAPI/Streaming/channels/%d/picture';
    public const PATH_REBOOT = '/ISAPI/System/reboot';
    public const PATH_ALERT_STREAM = '/ISAPI/Event/notification/alertStream';
    public const PATH_ACS_EVENT = '/ISAPI/AccessControl/AcsEvent?format=json';

    /** @var callable|null fn(string $topic, string $message) */
    private $debug;

    /** Sperre „dauerhaft offen“: schreibende Anfragen mit alwaysOpen/remainOpen werden nicht gesendet. */
    private bool $blockHoldOpen = false;

    public function __construct(
        private readonly string $base,
        private readonly string $user,
        private readonly string $pass,
        ?callable $debug = null
    ) {
        $this->debug = $debug;
    }

    public function base(): string
    {
        return $this->base;
    }

    public function blockHoldOpen(bool $block): self
    {
        $this->blockHoldOpen = $block;
        return $this;
    }

    /**
     * Enthält Pfad oder Rumpf einen Befehl für „dauerhaft offen“ (alwaysOpen, remainOpen)?
     * Prüft auch JSON-Escapes, XML-/HTML-Entities, URL-Kodierung, Groß-/Kleinschreibung und
     * Trennzeichen, damit sich die Sperre nicht umgehen lässt. Trifft bewusst auch harmlose
     * Felder wie den Magnetkontakt-Typ „alwaysOpen“ (lieber zu streng).
     */
    public static function containsHoldOpen(string $text): bool
    {
        $parts = [$text];
        $json = json_decode($text, true);
        if (is_array($json)) {
            array_walk_recursive($json, static function ($v, $k) use (&$parts): void {
                $parts[] = (string) $k;
                $parts[] = is_scalar($v) ? (string) $v : '';
            });
        }
        $all = implode(' ', $parts);
        for ($i = 0; $i < 3; $i++) { // mehrfach kodiert
            $all = html_entity_decode(rawurldecode($all), ENT_QUOTES | ENT_XML1 | ENT_HTML5, 'UTF-8');
        }
        $flat = strtolower((string) preg_replace('/[^a-z]/i', '', $all));
        return str_contains($flat, 'alwaysopen') || str_contains($flat, 'remainopen');
    }

    /**
     * @return array{code:int, ctype:string, body:string, error:string}
     */
    public function request(string $method, string $path, string $body = '', int $timeout = 8): array
    {
        if ($this->blockHoldOpen && strtoupper($method) !== 'GET' && self::containsHoldOpen($path . ' ' . $body)) {
            $this->log('HTTP', sprintf('%s %s -> blocked (permanently open disabled)', $method, $path));
            throw new RuntimeException('Permanently open is disabled in the instance configuration');
        }
        $ch = curl_init($this->base . $path);
        $headers = [];
        if ($body !== '') {
            $headers[] = str_starts_with(ltrim($body), '{') ? 'Content-Type: application/json' : 'Content-Type: application/xml';
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPAUTH       => CURLAUTH_DIGEST | CURLAUTH_BASIC,
            CURLOPT_USERPWD        => $this->user . ':' . $this->pass,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        $result = [
            'code'  => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            'ctype' => (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
            'body'  => is_string($resp) ? $resp : '',
            'error' => curl_error($ch),
        ];
        $this->log('HTTP', sprintf('%s %s -> %d %s%s', $method, $path, $result['code'], $result['ctype'],
            $result['error'] !== '' ? ' ERR ' . $result['error'] : ''));
        return $result;
    }

    /** Liefert true, wenn die Türstation den Befehl mit statusCode 1 (OK) quittiert hat. */
    public static function isOk(array $result): bool
    {
        if ($result['code'] !== 200) {
            return false;
        }
        $body = $result['body'];
        if (str_contains($body, '<statusCode>')) {
            return str_contains($body, '<statusCode>1</statusCode>');
        }
        if (str_starts_with(ltrim($body), '{')) {
            $json = json_decode($body, true);
            return !is_array($json) || !isset($json['statusCode']) || (int) $json['statusCode'] === 1;
        }
        return true;
    }

    /** Fehlertext aus einer ResponseStatus-Antwort (XML oder JSON), sonst HTTP-Code. */
    public static function errorText(array $result): string
    {
        if ($result['error'] !== '') {
            return $result['error'];
        }
        if (preg_match('/<subStatusCode>([^<]*)</', $result['body'], $m)) {
            return $m[1];
        }
        $json = json_decode($result['body'], true);
        if (is_array($json) && isset($json['subStatusCode'])) {
            return (string) $json['subStatusCode'];
        }
        return 'HTTP ' . $result['code'];
    }

    /** @return array<string,string> Geräteinfo (model, firmwareVersion, serialNumber, deviceName, macAddress) */
    public function deviceInfo(): array
    {
        $r = $this->request('GET', self::PATH_DEVICE_INFO);
        if ($r['code'] !== 200) {
            throw new RuntimeException(self::errorText($r), $r['code']);
        }
        $info = [];
        foreach (['model', 'firmwareVersion', 'firmwareReleasedDate', 'serialNumber', 'deviceName', 'macAddress'] as $tag) {
            if (preg_match('/<' . $tag . '>([^<]*)</', $r['body'], $m)) {
                $info[$tag] = html_entity_decode($m[1], ENT_QUOTES | ENT_XML1);
            }
        }
        return $info;
    }

    /** "idle" | "ring" | "onCall" | "" bei Fehler */
    public function callStatus(): string
    {
        $r = $this->request('GET', self::PATH_CALL_STATUS, '', 5);
        if ($r['code'] !== 200) {
            return '';
        }
        $json = json_decode($r['body'], true);
        return (string) ($json['CallStatus']['status'] ?? '');
    }

    public function callSignal(string $cmd): bool
    {
        $r = $this->request('PUT', self::PATH_CALL_SIGNAL, json_encode(['CallSignal' => ['cmdType' => $cmd]]));
        return self::isOk($r);
    }

    /** $cmd: open | close | alwaysOpen | resume */
    public function door(int $door, string $cmd = 'open'): bool
    {
        $r = $this->request('PUT', self::PATH_DOOR . $door, '<RemoteControlDoor><cmd>' . $cmd . '</cmd></RemoteControlDoor>');
        return self::isOk($r);
    }

    /** JPEG-Daten oder '' */
    public function picture(int $channel = 101): string
    {
        $r = $this->request('GET', sprintf(self::PATH_PICTURE, $channel), '', 10);
        if ($r['code'] !== 200 || !str_starts_with($r['ctype'], 'image/')) {
            return '';
        }
        return $r['body'];
    }

    public function reboot(): bool
    {
        return self::isOk($this->request('PUT', self::PATH_REBOOT));
    }

    /**
     * Zutrittsereignisse der Türstation (neueste zuletzt).
     *
     * @return array<int, array<string,mixed>>
     */
    public function recentEvents(int $count = 10): array
    {
        $cond = static fn (int $pos, int $max): string => json_encode(['AcsEventCond' => [
            'searchID' => 'vdm10', 'searchResultPosition' => $pos, 'maxResults' => $max, 'major' => 0, 'minor' => 0,
        ]]);
        $r = $this->request('POST', self::PATH_ACS_EVENT, $cond(0, 1));
        $json = json_decode($r['body'], true);
        $total = (int) ($json['AcsEvent']['totalMatches'] ?? 0);
        if ($total === 0) {
            return [];
        }
        $pos = max(0, $total - $count);
        $r = $this->request('POST', self::PATH_ACS_EVENT, $cond($pos, min(30, $count)));
        $json = json_decode($r['body'], true);
        return is_array($json['AcsEvent']['InfoList'] ?? null) ? $json['AcsEvent']['InfoList'] : [];
    }

    /**
     * Holt eine frische Digest-Challenge (401 auf einen unauthentifizierten Aufruf).
     *
     * @return array<string,string> realm, nonce, qop, opaque — leer bei Fehler
     */
    public function challenge(): array
    {
        $ch = curl_init($this->base . self::PATH_DEVICE_INFO);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_NOBODY => false,
            CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $resp = curl_exec($ch);
        if (!is_string($resp) || !preg_match('/^WWW-Authenticate:\s*Digest\s+(.*)$/mi', $resp, $m)) {
            return [];
        }
        return self::parseChallenge($m[1]);
    }

    /** @return array<string,string> */
    public static function parseChallenge(string $header): array
    {
        $out = [];
        if (preg_match_all('/(\w+)=("([^"]*)"|([^\s,]*))/', $header, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $out[strtolower($m[1])] = $m[3] !== '' ? $m[3] : ($m[4] ?? '');
            }
        }
        return $out;
    }

    /** Authorization-Header für eine Anfrage mit der gegebenen Challenge (RFC 2617, qop=auth). */
    public function digestHeader(string $method, string $uri, array $challenge): string
    {
        $realm = $challenge['realm'] ?? '';
        $nonce = $challenge['nonce'] ?? '';
        $qop = str_contains($challenge['qop'] ?? '', 'auth') ? 'auth' : '';
        $nc = '00000001';
        $cnonce = bin2hex(random_bytes(8));
        $ha1 = md5($this->user . ':' . $realm . ':' . $this->pass);
        $ha2 = md5($method . ':' . $uri);
        $response = $qop === 'auth'
            ? md5($ha1 . ':' . $nonce . ':' . $nc . ':' . $cnonce . ':auth:' . $ha2)
            : md5($ha1 . ':' . $nonce . ':' . $ha2);
        $h = sprintf('Digest username="%s", realm="%s", nonce="%s", uri="%s", response="%s"',
            $this->user, $realm, $nonce, $uri, $response);
        if ($qop === 'auth') {
            $h .= sprintf(', qop=auth, nc=%s, cnonce="%s"', $nc, $cnonce);
        }
        if (($challenge['opaque'] ?? '') !== '') {
            $h .= ', opaque="' . $challenge['opaque'] . '"';
        }
        return $h;
    }

    /** Vollständige HTTP-Anfrage (Rohbytes) für den Alert-Stream über einen TCP-Socket. */
    public function alertStreamRequest(string $host, array $challenge): string
    {
        $uri = self::PATH_ALERT_STREAM;
        return "GET {$uri} HTTP/1.1\r\n"
            . "Host: {$host}\r\n"
            . 'Authorization: ' . $this->digestHeader('GET', $uri, $challenge) . "\r\n"
            . "Accept: */*\r\n"
            . "Connection: keep-alive\r\n\r\n";
    }

    private function log(string $topic, string $message): void
    {
        if ($this->debug !== null) {
            ($this->debug)($topic, $message);
        }
    }
}
