<?php

declare(strict_types=1);

/**
 * Prüft ohne Symcon und ohne Türstation die Sperre „dauerhaft offen“ im HTTP-Client:
 * Befehle mit alwaysOpen/remainOpen werden in jeder Schreibweise erkannt und nie gesendet.
 * Aufruf: php tests/HoldOpenGuardTest.php
 */
require __DIR__ . '/../VDM10/libs/VDM10Isapi.php';

$fails = 0;
$check = static function (string $what, bool $ok) use (&$fails): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    $fails += $ok ? 0 : 1;
};

$blocked = [
    'XML-Befehl des Moduls'      => '<RemoteControlDoor><cmd>alwaysOpen</cmd></RemoteControlDoor>',
    'JSON'                       => '{"RemoteControlDoor":{"cmd":"alwaysOpen"}}',
    'JSON mit Unicode-Escape'    => '{"RemoteControlDoor":{"cmd":"alwaysOpen"}}',
    'XML-Entities'               => '<cmd>&#97;lways&#x4F;pen</cmd>',
    'URL-kodiert im Pfad'        => '/ISAPI/AccessControl/RemoteControl/door/1?cmd=always%4Fpen',
    'Großschreibung'             => '<cmd>ALWAYSOPEN</cmd>',
    'mit Trennzeichen'           => '<cmd>always_open</cmd>',
    'Tür-Zeitplan remainOpen'    => '{"DoorStatusWeekPlanCfg":{"WeekPlanCfg":[{"doorStatus":"remainOpen"}]}}',
    'doppelt kodiert'            => 'always%2525%34%46pen',
];
foreach ($blocked as $what => $text) {
    $check('erkannt: ' . $what, VDM10Isapi::containsHoldOpen($text));
}
foreach ([
    'Tür öffnen (Puls)'  => '<RemoteControlDoor><cmd>open</cmd></RemoteControlDoor>',
    'Normalbetrieb'      => '<RemoteControlDoor><cmd>resume</cmd></RemoteControlDoor>',
    'Person anlegen'     => '{"UserInfo":{"employeeNo":"9","name":"Max"}}',
] as $what => $text) {
    $check('nicht gesperrt: ' . $what, !VDM10Isapi::containsHoldOpen($text));
}

// Client mit Sperre: schreibende Anfrage wird vor dem Senden abgelehnt (Ziel nicht erreichbar → kein Netzverkehr nötig)
$api = (new VDM10Isapi('http://192.0.2.1:9', 'admin', 'x'))->blockHoldOpen(true);
try {
    $api->door(1, 'alwaysOpen');
    $check('door(alwaysOpen) abgelehnt', false);
} catch (RuntimeException $e) {
    $check('door(alwaysOpen) abgelehnt', str_contains($e->getMessage(), 'disabled'));
}
try {
    $api->request('PUT', '/ISAPI/AccessControl/RemoteControl/door/1?format=json', '{"RemoteControlDoor":{"cmd":"alwaysOpen"}}');
    $check('Durchgriff mit Escape abgelehnt', false);
} catch (RuntimeException $e) {
    $check('Durchgriff mit Escape abgelehnt', true);
}
$t = microtime(true);
$r = $api->request('GET', '/ISAPI/AccessControl/Door/param/1?x=alwaysOpen', '', 1);
$check('Lesen bleibt erlaubt (Anfrage geht raus)', $r['code'] === 0 && $r['error'] !== '');
$open = (new VDM10Isapi('http://192.0.2.1:9', 'admin', 'x'))->blockHoldOpen(false);
$r = $open->request('PUT', '/ISAPI/AccessControl/RemoteControl/door/1', '<cmd>alwaysOpen</cmd>', 1);
$check('ohne Sperre wird gesendet', $r['code'] === 0 && $r['error'] !== '');

exit($fails === 0 ? 0 : 1);
