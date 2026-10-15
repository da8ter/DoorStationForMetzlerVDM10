<?php

declare(strict_types=1);

/**
 * ISAPI-Probe für die Metzler VDM10 (Hikvision DS-KD8003-Basis).
 *
 * Aufruf:  php tests/probe-isapi.php <host> <user> <passwort> [--push-host=IP --push-port=3777 --push-url=/hook/vdm10_test]
 *
 * Fragt nur lesend ab; einzige schreibenden Aktionen sind der optionale
 * httpHosts-Eintrag (nur mit --push-host) und dessen Test-Ereignis.
 * Zugangsdaten werden ausschließlich als Argumente übergeben.
 */

$args = $argv;
array_shift($args);
$opts = [];
$pos = [];
foreach ($args as $a) {
    if (str_starts_with($a, '--')) {
        [$k, $v] = array_pad(explode('=', substr($a, 2), 2), 2, '1');
        $opts[$k] = $v;
    } else {
        $pos[] = $a;
    }
}
if (count($pos) < 3) {
    fwrite(STDERR, "Aufruf: php probe-isapi.php <host> <user> <passwort> [--https] [--push-host=IP --push-port=3777 --push-url=/hook/vdm10_test]\n");
    exit(1);
}
[$host, $user, $pass] = $pos;
$scheme = isset($opts['https']) ? 'https' : 'http';
$base = "$scheme://$host";

function req(string $base, string $user, string $pass, string $method, string $path, string $body = ''): array
{
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPAUTH       => CURLAUTH_DIGEST | CURLAUTH_BASIC,
        CURLOPT_USERPWD        => "$user:$pass",
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HEADER         => false,
    ]);
    if ($body !== '') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [str_starts_with(ltrim($body), '{') ? 'Content-Type: application/json' : 'Content-Type: application/xml']);
    }
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $err = curl_error($ch);
    return ['code' => $code, 'ctype' => $ctype, 'body' => is_string($resp) ? $resp : '', 'err' => $err];
}

function show(string $label, array $r, int $max = 600): void
{
    $body = $r['body'];
    $isBin = str_starts_with($r['ctype'], 'image/');
    $preview = $isBin ? sprintf('<%d bytes %s>', strlen($body), $r['ctype']) : preg_replace('/\s+/', ' ', substr($body, 0, $max));
    printf("%-55s HTTP %3d %-28s %s\n", $label, $r['code'], $r['ctype'] !== '' ? substr($r['ctype'], 0, 28) : ($r['err'] !== '' ? 'ERR ' . $r['err'] : ''), $preview);
}

$get = [
    '/ISAPI/System/deviceInfo',
    '/ISAPI/System/capabilities',
    '/ISAPI/System/status',
    '/ISAPI/System/time',
    '/ISAPI/Security/users',
    '/ISAPI/VideoIntercom/capabilities',
    '/ISAPI/VideoIntercom/callStatus?format=json',
    '/ISAPI/VideoIntercom/callStatus?format=json&channelType=tripartitePlatform',
    '/ISAPI/VideoIntercom/callSignal/capabilities?format=json',
    '/ISAPI/VideoIntercom/callerInfo?format=json',
    '/ISAPI/VideoIntercom/keyCfg/1',
    '/ISAPI/VideoIntercom/SubModuleBacklight?format=json',
    '/ISAPI/AccessControl/RemoteControl/door/capabilities',
    '/ISAPI/AccessControl/Door/param/1',
    '/ISAPI/AccessControl/AcsEvent/capabilities?format=json',
    '/ISAPI/AccessControl/AcsCfg?format=json',
    '/ISAPI/AccessControl/UserInfo/capabilities?format=json',
    '/ISAPI/AccessControl/FingerPrintCfg/capabilities?format=json',
    '/ISAPI/Event/notification/httpHosts',
    '/ISAPI/Event/notification/httpHosts/capabilities',
    '/ISAPI/Event/triggers',
    '/ISAPI/Event/triggers/capabilities',
    '/ISAPI/Event/notification/alertStream/capabilities',
    '/ISAPI/System/IO/outputs',
    '/ISAPI/System/IO/inputs',
    '/ISAPI/System/IO/capabilities',
    '/ISAPI/System/Video/inputs/channels',
    '/ISAPI/Streaming/channels',
    '/ISAPI/Streaming/channels/101',
    '/ISAPI/Streaming/channels/102',
    '/ISAPI/System/Network/interfaces',
    '/ISAPI/System/Network/SIP/1?format=json',
    '/ISAPI/System/Network/SIP',
    '/ISAPI/System/TwoWayAudio/channels',
    '/ISAPI/SecurityCP/status/outputStatus?format=json',
    '/ISAPI/System/Audio/AudioOut/channels/1',
];

echo "== Probe $base als $user ==\n";
foreach ($get as $p) {
    show('GET ' . $p, req($base, $user, $pass, 'GET', $p));
}

echo "\n== Snapshot-Pfade ==\n";
foreach (['/ISAPI/Streaming/channels/101/picture', '/ISAPI/Streaming/channels/1/picture', '/ISAPI/Streaming/channels/102/picture', '/ISAPI/Streaming/channels/101/picture?videoResolutionWidth=640&videoResolutionHeight=360'] as $p) {
    show('GET ' . $p, req($base, $user, $pass, 'GET', $p));
}

echo "\n== POST-Suchen (lesend) ==\n";
show('POST AcsEvent search (letzte 5)', req($base, $user, $pass, 'POST', '/ISAPI/AccessControl/AcsEvent?format=json',
    json_encode(['AcsEventCond' => ['searchID' => 'probe1', 'searchResultPosition' => 0, 'maxResults' => 5, 'major' => 0, 'minor' => 0]])), 1500);
show('POST UserInfo search (3)', req($base, $user, $pass, 'POST', '/ISAPI/AccessControl/UserInfo/Search?format=json',
    json_encode(['UserInfoSearchCond' => ['searchID' => 'probe2', 'searchResultPosition' => 0, 'maxResults' => 3]])), 800);
show('POST outputStatus', req($base, $user, $pass, 'POST', '/ISAPI/SecurityCP/status/outputStatus?format=json',
    json_encode(['OutputCond' => ['searchID' => 'probe3', 'searchResultPosition' => 0, 'maxResults' => 4]])), 800);

echo "\n== RTSP ==\n";
$fp = @fsockopen($host, 554, $errno, $errstr, 3);
if ($fp === false) {
    echo "RTSP 554: nicht erreichbar ($errstr)\n";
} else {
    fwrite($fp, "OPTIONS rtsp://$host:554/Streaming/Channels/101 RTSP/1.0\r\nCSeq: 1\r\n\r\n");
    stream_set_timeout($fp, 3);
    $resp = fread($fp, 1024);
    fclose($fp);
    echo 'RTSP OPTIONS: ' . preg_replace('/\s+/', ' ', substr($resp, 0, 200)) . "\n";
    $fp = @fsockopen($host, 554, $errno, $errstr, 3);
    if ($fp !== false) {
        fwrite($fp, "DESCRIBE rtsp://$host:554/Streaming/Channels/101 RTSP/1.0\r\nCSeq: 2\r\nAccept: application/sdp\r\n\r\n");
        stream_set_timeout($fp, 3);
        $resp = fread($fp, 1024);
        fclose($fp);
        echo 'RTSP DESCRIBE (ohne Auth): ' . preg_replace('/\s+/', ' ', substr($resp, 0, 200)) . "\n";
    }
}

if (isset($opts['push-host'])) {
    $pushHost = $opts['push-host'];
    $pushPort = (int) ($opts['push-port'] ?? 3777);
    $pushUrl = $opts['push-url'] ?? '/hook/vdm10_test';
    echo "\n== httpHosts setzen auf http://$pushHost:$pushPort$pushUrl ==\n";
    $xml = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<HttpHostNotificationList><HttpHostNotification>'
        . '<id>1</id><url>' . htmlspecialchars($pushUrl) . '</url>'
        . '<protocolType>HTTP</protocolType><parameterFormatType>XML</parameterFormatType>'
        . '<addressingFormatType>ipaddress</addressingFormatType>'
        . '<ipAddress>' . $pushHost . '</ipAddress><portNo>' . $pushPort . '</portNo>'
        . '<httpAuthenticationMethod>none</httpAuthenticationMethod>'
        . '</HttpHostNotification></HttpHostNotificationList>';
    show('PUT httpHosts', req($base, $user, $pass, 'PUT', '/ISAPI/Event/notification/httpHosts', $xml));
    show('GET httpHosts (danach)', req($base, $user, $pass, 'GET', '/ISAPI/Event/notification/httpHosts'));
    show('POST httpHosts/1/test', req($base, $user, $pass, 'POST', '/ISAPI/Event/notification/httpHosts/1/test'));
}
echo "\nFertig.\n";
