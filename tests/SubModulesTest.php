<?php

declare(strict_types=1);

/**
 * Prüft ohne Symcon die Erweiterungsmodule: Modulliste, Klingeltasten (keyCfg-XML),
 * Display-Einstellungen und die Tastenerkennung in Ereignissen.
 * Aufruf: php tests/SubModulesTest.php
 */
require __DIR__ . '/../VDM10/libs/VDM10SubModules.php';
require __DIR__ . '/../VDM10/libs/VDM10Events.php';

$fails = 0;
$check = static function (string $what, bool $ok) use (&$fails): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    $fails += $ok ? 0 : 1;
};

// Modulliste: Antwort der VDM10 (15.10.2026) plus ein Touch-Display
$list = VDM10SubModules::parseList('{"SubModules":[{"id":2,"moduleType":"DS-KD-TDM","status":"offline","version":"V1.2"},{"id":1,"moduleType":"DS-KD-MFB","status":"online","version":"V1.0.1 build250513"}]}');
$check('Modulliste gelesen und sortiert', $list !== null && count($list) === 2 && $list[0]['id'] === 1 && $list[1]['type'] === 'DS-KD-TDM');
$check('Fehlerantwort ergibt null', VDM10SubModules::parseList('{"statusCode":4}') === null);
$check('Name Finger-/Kartenleser', VDM10SubModules::name('DS-KD-MFB') === 'Fingerprint and card reader module');
$check('unbekannter Typ', VDM10SubModules::name('DS-XY') === 'Extension module');
$check('TDM ist Display, MFB nicht', VDM10SubModules::isDisplay('DS-KD-TDM') && !VDM10SubModules::isDisplay('DS-KD-MFB'));

// Klingeltasten: Hauptmodul (echte Antwort) + Namensschild-Modul mit Beschriftung
$xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\r\n<KeyCfgList version=\"2.0\"><KeyCfg version=\"2.0\">\r\n<id>1</id>\r\n<module>main</module>\r\n<callMethod>callNumber</callMethod>\r\n<callNumber>1</callNumber>\r\n<enableCallCenter>false</enableCallCenter>\r\n<templateNo>0</templateNo>\r\n</KeyCfg>"
    . '<KeyCfg version="2.0"><id>2</id><module>sub</module><callMethod>callNumber</callMethod><callNumber>12</callNumber><customInfo>M&#252;ller &amp; Co</customInfo></KeyCfg></KeyCfgList>';
$keys = VDM10SubModules::parseKeys($xml);
$check('zwei Tasten gelesen', count($keys) === 2 && $keys[1]['room'] === 12);
$check('Beschriftung dekodiert', $keys[1]['label'] === 'Müller & Co');
$check('Name ohne Beschriftung', VDM10SubModules::keyLabel($keys[0], 'Taste %d', 'Zimmer %d') === 'Taste 1 · Zimmer 1');
$virtual = VDM10SubModules::parseKeys('<KeyCfgList><KeyCfg><id>1</id><module>main</module><callNumber>1</callNumber></KeyCfg><KeyCfg><id>5</id><callNumber>40</callNumber><customInfo>Empfang</customInfo></KeyCfg></KeyCfgList>');
$merged = VDM10SubModules::mergeKeys($keys, $virtual);
$check('virtuelle Taste ergänzt, doppelte nicht', count($merged) === 3 && $merged[2]['id'] === 5 && $merged[2]['virtual'] && !$merged[0]['virtual']);
$check('Taste aus Tastennummer', VDM10SubModules::keyForEvent($merged, 2, 0) === 2);
$check('Taste aus Zimmernummer', VDM10SubModules::keyForEvent($merged, 0, 40) === 5);
$check('unbekannte Taste 0', VDM10SubModules::keyForEvent($merged, 9, 77) === 0);

// XML schreiben: ersetzen, ergänzen, maskieren
$one = "<KeyCfg version=\"2.0\">\r\n<id>1</id>\r\n<callNumber>1</callNumber>\r\n<templateNo>0</templateNo>\r\n</KeyCfg>\r\n";
$out = VDM10SubModules::xmlSet($one, 'KeyCfg', 'callNumber', '7');
$check('callNumber ersetzt', str_contains($out, '<callNumber>7</callNumber>') && substr_count($out, '<callNumber>') === 1);
$out = VDM10SubModules::xmlSet($out, 'KeyCfg', 'customInfo', 'Tür <A> & "B"');
$check('customInfo vor </KeyCfg> ergänzt und maskiert', str_contains($out, '<customInfo>Tür &lt;A&gt; &amp; &quot;B&quot;</customInfo>' . "\r\n</KeyCfg>"));
$out = VDM10SubModules::xmlSet($out, 'KeyCfg', 'customInfo', '');
$check('customInfo leeren', str_contains($out, '<customInfo></customInfo>') && substr_count($out, '<customInfo>') === 1);
try {
    VDM10SubModules::xmlSet('<Other/>', 'KeyCfg', 'x', '1');
    $check('fehlende Wurzel wirft', false);
} catch (RuntimeException $e) {
    $check('fehlende Wurzel wirft', true);
}

// Display-Einstellungen nach Fähigkeiten (Format wie die Weboberfläche es liest)
$caps = VDM10SubModules::parseCaps('{"SubModulesCfg":{"Backlight":{"brightness":{"@min":1,"@max":10}},"enableKeypadTone":{"@opt":[true,false]},'
    . '"customCallCenterName":{"@min":1,"@max":16},"enableCallCenter":{"@opt":[true,false]},'
    . '"customInterface":{"@opt":["dial","contacts","callButton","card"],"associatedPicURL":[]},"themeMode":{"@opt":"dark,light"},"ringDirectlyEnabled":{"@opt":[true,false]}}}');
$check('Fähigkeiten gelesen', $caps['brightness'] === [1, 10] && $caps['tone'] && $caps['themes'] === ['dark', 'light'] && count($caps['start']) === 4 && $caps['ringDirectly']);
$cfg = VDM10SubModules::parseConfig('{"SubModulesCfg":{"Backlight":{"brightness":7},"enableKeypadTone":true,"customInterface":"callButton","themeMode":"dark","enableCallCenter":false,"customCallCenterName":"Zentrale","ringDirectlyEnabled":false}}');
$check('Werte gelesen', $cfg['brightness'] === 7 && $cfg['tone'] && $cfg['start'] === 'callButton' && $cfg['callCenterName'] === 'Zentrale');
$body = VDM10SubModules::payload($caps, array_merge($cfg, ['brightness' => 15, 'theme' => 'pink', 'callCenterName' => str_repeat('x', 30)]))['SubModulesCfg'];
$check('Helligkeit begrenzt', $body['Backlight']['brightness'] === 10);
$check('ungültiges Design weggelassen', !isset($body['themeMode']));
$check('Name gekürzt', mb_strlen($body['customCallCenterName']) === 16);
$small = VDM10SubModules::payload(VDM10SubModules::parseCaps('{"SubModulesCfg":{"Backlight":{"brightness":{"@min":1,"@max":5}}}}'), $cfg)['SubModulesCfg'];
$check('nur unterstützte Felder', array_keys($small) === ['Backlight'] && $small['Backlight']['brightness'] === 5);
$check('Modul ohne Einstellungen', VDM10SubModules::parseCaps('{"statusCode":4}')['brightness'] === null);

// Zugangsarten je nach Modulen
$mod = static fn (string ...$types): array => array_map(static fn (string $t): array => ['type' => $t], $types);
$check('ohne Module: nichts', VDM10SubModules::credentials([]) === ['finger' => false, 'card' => false, 'pin' => false]);
$check('Liste unbekannt: alles', VDM10SubModules::credentials(null) === ['finger' => true, 'card' => true, 'pin' => true]);
$check('Finger-/Kartenleser (eure Anlage)', VDM10SubModules::credentials($mod('DS-KD-MFB')) === ['finger' => true, 'card' => true, 'pin' => false]);
$check('Touch-Display: Karte und PIN', VDM10SubModules::credentials($mod('DS-KD-TDM', 'DS-KD-KK')) === ['finger' => false, 'card' => true, 'pin' => true]);
$check('Tastenfeld: nur PIN', VDM10SubModules::credentials($mod('ds-kd-kp')) === ['finger' => false, 'card' => false, 'pin' => true]);
$check('Wiegand am Sicherheitsmodul: Karte', VDM10SubModules::credentials($mod('DS-KD-KK'), true)['card'] === true);

// Tastenerkennung in Ereignissen
$ring = '{"eventType":"AccessControllerEvent","eventState":"active","dateTime":"2026-10-14T22:59:09+01:00","AccessControllerEvent":{"deviceName":"Türklingel","majorEventType":1,"subEventType":3,"serialNo":46,"currentEvent":true}}';
$e = VDM10Events::parseNotification($ring);
$check('1/3 ist Bewegung, ohne Tastennummer', $e['kind'] === VDM10Events::KIND_MOTION && $e['key'] === 0 && $e['room'] === 0);
$e = VDM10Events::parseNotification(str_replace('"serialNo":46', '"serialNo":46,"buttonNo":3', $ring));
$check('buttonNo erkannt', $e['key'] === 3);
$e = VDM10Events::parseNotification('{"eventType":"VideoIntercomEvent","VideoIntercomEvent":{"CallerInfo":{"callNumber":"12"}}}');
$check('Zimmernummer verschachtelt erkannt', $e['room'] === 12 && $e['key'] === 0);
$e = VDM10Events::parseNotification('<EventNotificationAlert><eventType>AccessControllerEvent</eventType><majorEventType>1</majorEventType><subEventType>3</subEventType><keyNo>2</keyNo></EventNotificationAlert>');
$check('keyNo im XML erkannt', $e['key'] === 2);
$check('Abfrage-Ereignis ohne Taste', VDM10Events::fromAcsEvent(['major' => 1, 'minor' => 3])['key'] === 0);

exit($fails === 0 ? 0 : 1);
