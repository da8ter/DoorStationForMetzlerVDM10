<?php

declare(strict_types=1);

/**
 * Prüft ohne Symcon die Gültigkeit von Personen: unbefristet, offenes Ende, ein Tag, Fehler.
 * Aufruf: php tests/PersonValidityTest.php
 */
require __DIR__ . '/../VDM10/libs/VDM10PersonsTrait.php';

final class Probe
{
    use VDM10PersonsTrait;

    public array $written = [];

    protected function Translate(string $text): string
    {
        return $text;
    }

    private function modifyPerson(string $no, array $fields): bool
    {
        $this->written = $fields;
        return true;
    }

    public function valid(int $begin, int $end): array
    {
        return $this->validity($begin, $end);
    }
}

$fails = 0;
$check = static function (string $what, bool $ok) use (&$fails): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    $fails += $ok ? 0 : 1;
};
$p = new Probe();
$day = mktime(0, 0, 0, 11, 3, 2026);

$v = $p->valid(0, 0);
$check('unbefristet: nicht aktiv', $v['enable'] === false);
$v = $p->valid($day, 0);
$check('offenes Ende: aktiv ab Beginn, Ende in der Zukunft (nicht 1970)', $v['enable'] === true && $v['beginTime'] === '2026-11-03T00:00:00' && (int) substr($v['endTime'], 0, 4) >= 2036);
$v = $p->valid($day, $day);
$check('ein Tag: 00:00 bis 23:59:59 desselben Tags', $v['beginTime'] === '2026-11-03T00:00:00' && $v['endTime'] === '2026-11-03T23:59:59');
$check('ein Tag über SetPersonValidity erlaubt', $p->SetPersonValidity('7', $day, $day) && $p->written['Valid']['endTime'] === '2026-11-03T23:59:59');
try {
    $p->SetPersonValidity('7', $day, $day - 86400);
    $check('Ende vor Beginn abgelehnt', false);
} catch (InvalidArgumentException $e) {
    $check('Ende vor Beginn abgelehnt', true);
}
$check('offenes Ende über SetPersonValidity', $p->SetPersonValidity('7', $day, 0) && (int) substr($p->written['Valid']['endTime'], 0, 4) >= 2036);

exit($fails === 0 ? 0 : 1);
