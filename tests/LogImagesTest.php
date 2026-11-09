<?php

declare(strict_types=1);

/**
 * Prüft ohne Symcon die Bilder des Ereignis-Logs: Medienobjekte in der Kategorie „Ereignisbilder“
 * unter der Instanz, je Art die letzten zehn, Löschen mit dem Log-Eintrag.
 * Aufruf: php tests/LogImagesTest.php
 */
const MEDIATYPE_IMAGE = 1;
require __DIR__ . '/../VDM10/libs/VDM10LogTrait.php';

/** Objekte des Tests: ID => [Art, Eltern-ID, Ident, Name, Position, Inhalt] */
$GLOBALS['objects'] = [];
$GLOBALS['nextId'] = 20000;
function testCreate(string $kind): int
{
    $id = $GLOBALS['nextId']++;
    $GLOBALS['objects'][$id] = [$kind, 0, '', '', 0, ''];
    return $id;
}
function IPS_CreateCategory(): int
{
    return testCreate('category');
}
function IPS_CreateMedia(int $type): int
{
    return testCreate('media');
}
function IPS_DeleteMedia(int $id, bool $file): bool
{
    unset($GLOBALS['objects'][$id]);
    return true;
}
function IPS_SetParent(int $id, int $parent): bool
{
    $GLOBALS['objects'][$id][1] = $parent;
    return true;
}
function IPS_SetIdent(int $id, string $ident): bool
{
    $GLOBALS['objects'][$id][2] = $ident;
    return true;
}
function IPS_SetName(int $id, string $name): bool
{
    $GLOBALS['objects'][$id][3] = $name;
    return true;
}
function IPS_SetPosition(int $id, int $position): bool
{
    $GLOBALS['objects'][$id][4] = $position;
    return true;
}
function IPS_SetMediaFile(int $id, string $file, bool $exists): bool
{
    return true;
}
function IPS_SetMediaContent(int $id, string $content): bool
{
    $GLOBALS['objects'][$id][5] = $content;
    return true;
}
function IPS_GetObjectIDByIdent(string $ident, int $parent): int
{
    foreach ($GLOBALS['objects'] as $id => [, $p, $i]) {
        if ($p === $parent && $i === $ident) {
            return $id;
        }
    }
    trigger_error('Objekt nicht gefunden', E_USER_WARNING);
    return 0;
}

final class Probe
{
    use VDM10LogTrait;

    public int $InstanceID = 30293;
    public array $attributes = ['EventLog' => '[]'];
    public int $clock = 1794200000;

    protected function GetIDForIdent(string $ident): int
    {
        return IPS_GetObjectIDByIdent($ident, $this->InstanceID);
    }

    protected function ReadAttributeString(string $name): string
    {
        return $this->attributes[$name];
    }

    protected function WriteAttributeString(string $name, string $value): void
    {
        $this->attributes[$name] = $value;
    }

    protected function ReadPropertyInteger(string $name): int
    {
        return 1;
    }

    protected function Translate(string $text): string
    {
        return $text;
    }

    private function tileChanged(): void
    {
    }

    private function notifyEvent(string $kind, string $text, array $extra): void
    {
    }

    /** Ereignis mit Bild wie logRing()/logAccess(); liefert die Log-ID. */
    public function event(string $kind, string $marker, array $extra = []): int
    {
        $id = $this->logEvent($kind, $extra);
        $this->attachLogImage($id, $marker === '' ? '' : "\xFF\xD8" . $marker);
        return $id;
    }

    /** Ereignis mit beliebigen Bilddaten (ohne JPEG-Kennung). */
    public function raw(string $kind, string $bytes): int
    {
        $id = $this->logEvent($kind);
        $this->attachLogImage($id, $bytes);
        return $id;
    }

    public function log(): array
    {
        return $this->readLog();
    }

    public function imageOf(int $id): int
    {
        return $this->logImageId($id);
    }
}

$fails = 0;
$check = static function (string $what, bool $ok) use (&$fails): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    $fails += $ok ? 0 : 1;
};
$of = static fn (string $kind): array => array_filter($GLOBALS['objects'], static fn (array $o): bool => $o[0] === $kind);
$content = static fn (int $media): string => substr((string) base64_decode($GLOBALS['objects'][$media][5], true), 2);

$p = new Probe();
$p->event('unlock', '');
$check('Ereignis ohne Bild legt nichts an', $GLOBALS['objects'] === []);

$first = $p->event('ring', 'R0');
$categories = $of('category');
$category = (int) array_key_first($categories);
$check('Kategorie unter der Instanz mit Ident und Name', count($categories) === 1
    && $GLOBALS['objects'][$category][1] === 30293 && $GLOBALS['objects'][$category][2] === 'EVENT_IMAGES'
    && $GLOBALS['objects'][$category][3] === 'Event images');
$media = $p->imageOf($first);
$entry = $p->log()[0];
$check('Bild als Medium in der Kategorie', $media > 0 && $GLOBALS['objects'][$media][1] === $category
    && $GLOBALS['objects'][$media][2] === 'IMG_' . $first && $content($media) === 'R0' && $entry['img'] === true);
$check('Name mit Ereignis und Zeitpunkt', $GLOBALS['objects'][$media][3] === 'Doorbell · ' . date('d.m.Y H:i:s', (int) $entry['ts']));
$check('Position: neuestes oben', $GLOBALS['objects'][$media][4] === -(int) $entry['ts']);

$access = $p->event('access', 'A0', ['name' => 'Anna', 'method' => 'finger']);
$check('Zutritt nennt die Person', str_starts_with($GLOBALS['objects'][$p->imageOf($access)][3], 'Access · Anna · '));
$check('nur eine Kategorie', count($of('category')) === 1);

$gif = $p->raw('snapshot', 'GIF89a');
$check('kein JPEG: kein Medium, Eintrag ohne Bild', count($of('media')) === 2 && $p->imageOf($gif) === 0 && empty($p->log()[0]['img']));

for ($i = 1; $i <= 10; $i++) {
    $p->event('ring', 'R' . $i);
}
$check('je Art höchstens zehn Bilder', count(array_filter($p->log(), static fn (array $e): bool => $e['kind'] === 'ring' && !empty($e['img']))) === 10);
$check('ältestes Klingelbild gelöscht, Log-Eintrag ohne Bild', $p->imageOf($first) === 0
    && !array_filter($p->log(), static fn (array $e): bool => $e['id'] === $first && !empty($e['img'])));
$check('andere Arten bleiben', $p->imageOf($access) > 0);

for ($i = 0; $i < 50; $i++) {
    $p->event('unlock', '');
}
$check('fällt ein Eintrag aus dem Log, geht sein Bild mit', count($of('media')) === 0 && $p->imageOf($access) === 0);

exit($fails === 0 ? 0 : 1);
