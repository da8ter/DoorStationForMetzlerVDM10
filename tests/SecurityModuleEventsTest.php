<?php

declare(strict_types=1);

/**
 * Prüft ohne Symcon die Zuordnung der Türkontakt- und Sicherheitsmodul-Ereignisse.
 * Aufruf: php tests/SecurityModuleEventsTest.php
 */
const VARIABLE_PRESENTATION_VALUE_PRESENTATION = 'vp';
require __DIR__ . '/../VDM10/libs/VDM10Events.php';
require __DIR__ . '/../VDM10/libs/VDM10SecurityModuleTrait.php';

final class Probe
{
    use VDM10SecurityModuleTrait;

    public array $values = ['DOOR1_CONTACT' => false, 'DOOR1_ALARM' => false, 'DOOR2_CONTACT' => false, 'DOOR2_ALARM' => false, 'SECURITY_MODULE' => false];

    protected function GetIDForIdent(string $ident): int
    {
        return array_key_exists($ident, $this->values) ? 1 : 0;
    }

    protected function SetValue(string $ident, mixed $value): bool
    {
        $this->values[$ident] = $value;
        return true;
    }

    public function feed(int $major, int $minor, int $door = 1): bool
    {
        return $this->securityModuleEvent(VDM10Events::normalize('AccessControllerEvent', 'active', $major, $minor, true, '', $door, ''));
    }
}

$fails = 0;
$check = static function (string $what, bool $ok) use (&$fails): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    $fails += $ok ? 0 : 1;
};
$p = new Probe();
$check('5/25 Tür 1 offen', $p->feed(5, 25) && $p->values['DOOR1_CONTACT'] === true);
$check('5/28 Tür 1 zu lange offen', $p->feed(5, 28) && $p->values['DOOR1_ALARM'] === true);
$check('5/26 Tür 1 zu, Alarm aus', $p->feed(5, 26) && $p->values['DOOR1_CONTACT'] === false && $p->values['DOOR1_ALARM'] === false);
$check('5/25 Tür 2 offen', $p->feed(5, 25, 2) && $p->values['DOOR2_CONTACT'] === true && $p->values['DOOR1_CONTACT'] === false);
$check('1/0x1048 Notruf ist kein Türalarm', $p->feed(1, 0x1048, 2) === false && ($p->values['DOOR2_ALARM'] ?? false) === false);
$check('2/1040 Sicherheitsmodul online', $p->feed(2, 1040) && $p->values['SECURITY_MODULE'] === true);
$check('2/1041 Batterie schwach betrifft nicht das Sicherheitsmodul', $p->feed(2, 1041) === false && $p->values['SECURITY_MODULE'] === true);
$check('2/1039 Sicherheitsmodul offline', $p->feed(2, 1039) && $p->values['SECURITY_MODULE'] === false);
$kind = static fn (int $major, int $minor): string => VDM10Events::normalize('AccessControllerEvent', 'active', $major, $minor, true, '', 1, '')['kind'];
$check('1/0x1048 Notruf', $kind(1, 0x1048) === VDM10Events::KIND_EMERGENCY);
$check('1/0x1049 Notruf beendet', $kind(1, 0x1049) === VDM10Events::KIND_OTHER);
$check('1/0x40A Nötigungsalarm', $kind(1, 0x40A) === VDM10Events::KIND_DURESS);
$check('1/3 Bewegung', $kind(1, 3) === VDM10Events::KIND_MOTION);
$check('5/37 Klingeln', $kind(5, 37) === VDM10Events::KIND_RING);
$check('5/1281 ist kein Klingeln', $kind(5, 1281) === VDM10Events::KIND_OTHER);
$check('5/21 Schloss geöffnet bleibt beim Hauptmodul', $p->feed(5, 21) === false);
exit($fails === 0 ? 0 : 1);
