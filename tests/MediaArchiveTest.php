<?php

declare(strict_types=1);

/**
 * Prüft ohne Symcon das Snapshot-Archiv: Plätze entstehen erst, wenn ein Bild hineinrückt
 * (leere Bildmedien zeigt die Konsole rot), leere Altplätze und Plätze über der Archivgröße verschwinden.
 * Aufruf: php tests/MediaArchiveTest.php
 */
const MEDIATYPE_IMAGE = 1;
const MEDIATYPE_STREAM = 3;
require __DIR__ . '/../VDM10/libs/VDM10MediaTrait.php';

/** Medienobjekte des Tests: ID => [Ident, Name, Base64-Inhalt] */
$GLOBALS['media'] = [];
$GLOBALS['nextId'] = 100;
function IPS_CreateMedia(int $type): int
{
    $id = $GLOBALS['nextId']++;
    $GLOBALS['media'][$id] = ['', '', ''];
    return $id;
}
function IPS_DeleteMedia(int $id, bool $file): bool
{
    unset($GLOBALS['media'][$id]);
    return true;
}
function IPS_GetMedia(int $id): array
{
    $size = strlen($GLOBALS['media'][$id][2]);
    return ['MediaSize' => $size, 'MediaIsAvailable' => $size > 0, 'MediaFile' => ''];
}
function IPS_GetMediaContent(int $id): string
{
    return $GLOBALS['media'][$id][2];
}
function IPS_SetMediaContent(int $id, string $content): bool
{
    $GLOBALS['media'][$id][2] = $content;
    return true;
}
function IPS_SetIdent(int $id, string $ident): bool
{
    $GLOBALS['media'][$id][0] = $ident;
    return true;
}
function IPS_SetName(int $id, string $name): bool
{
    $GLOBALS['media'][$id][1] = $name;
    return true;
}
function IPS_SetParent(int $id, int $parent): bool
{
    return true;
}
function IPS_SetPosition(int $id, int $position): bool
{
    return true;
}
function IPS_SetMediaCached(int $id, bool $cached): bool
{
    return true;
}
function IPS_SetMediaFile(int $id, string $file, bool $exists): bool
{
    return true;
}
function IPS_SendMediaEvent(int $id): bool
{
    return true;
}

final class Probe
{
    use VDM10MediaTrait;

    public int $InstanceID = 30293;
    public int $archive = 3;

    protected function GetIDForIdent(string $ident): int
    {
        foreach ($GLOBALS['media'] as $id => [$i]) {
            if ($i === $ident) {
                return $id;
            }
        }
        return 0;
    }

    protected function ReadPropertyInteger(string $name): int
    {
        return $this->archive;
    }

    protected function ReadPropertyBoolean(string $name): bool
    {
        return false;
    }

    protected function ReadPropertyString(string $name): string
    {
        return '';
    }

    protected function Translate(string $text): string
    {
        return $text;
    }

    protected function SendDebug(string $message, string $data, int $format): void
    {
    }

    private function tileChanged(): void
    {
    }

    public function apply(): void
    {
        $this->maintainMedia();
    }

    public function snapshot(string $marker): void
    {
        $this->storeSnapshot("\xFF\xD8" . $marker);
    }
}

$fails = 0;
$check = static function (string $what, bool $ok) use (&$fails): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    $fails += $ok ? 0 : 1;
};
/** Ident => Bildinhalt (dekodiert, ohne JPEG-Kennung) */
$slots = static function (): array {
    $out = [];
    foreach ($GLOBALS['media'] as [$ident, , $content]) {
        $out[$ident] = substr((string) base64_decode($content, true), 2);
    }
    ksort($out);
    return $out;
};

$p = new Probe();
$p->apply();
$check('ohne Bild nur der aktuelle Snapshot', array_keys($slots()) === ['SNAPSHOT']);

$p->snapshot('A');
$check('erstes Bild legt keinen Archivplatz an', $slots() === ['SNAPSHOT' => 'A']);

$p->snapshot('B');
$check('zweites Bild füllt Platz 1', $slots() === ['SNAPSHOT' => 'B', 'SNAPSHOT_1' => 'A']);
$check('Name des Archivplatzes', in_array('Snapshot -1', array_column($GLOBALS['media'], 1), true));

$p->snapshot('C');
$p->snapshot('D');
$p->snapshot('E');
$check('Archiv wächst nicht über die Größe', $slots() === ['SNAPSHOT' => 'E', 'SNAPSHOT_1' => 'D', 'SNAPSHOT_2' => 'C', 'SNAPSHOT_3' => 'B']);

$p->archive = 1;
$p->apply();
$check('kleineres Archiv entfernt die oberen Plätze', $slots() === ['SNAPSHOT' => 'E', 'SNAPSHOT_1' => 'D']);

// Leere Plätze aus älteren Versionen (sofort angelegt, nie gefüllt)
$p->archive = 10;
foreach ([2, 3, 4] as $i) {
    IPS_SetIdent(IPS_CreateMedia(MEDIATYPE_IMAGE), 'SNAPSHOT_' . $i);
}
$p->apply();
$check('leere Altplätze verschwinden, gefüllte bleiben', $slots() === ['SNAPSHOT' => 'E', 'SNAPSHOT_1' => 'D']);

exit($fails === 0 ? 0 : 1);
