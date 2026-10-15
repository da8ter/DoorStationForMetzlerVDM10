<?php

declare(strict_types=1);

/**
 * Prüft ohne Symcon den abgesicherten Bild-Hook: Token, unbekannte Bilder, Cache-Header, ETag.
 * Aufruf: php tests/ImageHookTest.php
 */
const MEDIATYPE_IMAGE = 1;
require __DIR__ . '/../VDM10/libs/VDM10ImageHookTrait.php';

/** Medienobjekte des Tests: ID => [Typ, Base64-Inhalt, CRC] */
$GLOBALS['media'] = [];
function IPS_MediaExists(int $id): bool
{
    return isset($GLOBALS['media'][$id]);
}
function IPS_GetMedia(int $id): array
{
    [$type, $content, $crc] = $GLOBALS['media'][$id];
    return ['MediaType' => $type, 'MediaSize' => strlen($content), 'MediaCRC' => $crc, 'MediaUpdated' => 1700000000];
}
function IPS_GetMediaContent(int $id): string
{
    return $GLOBALS['media'][$id][1];
}

final class Probe
{
    use VDM10ImageHookTrait;

    private const MEDIA_SNAPSHOT = 'SNAPSHOT';

    public int $InstanceID = 30293;
    public array $headers = [];
    public int $status = 200;
    public array $attributes = ['ImageHookToken' => '', 'PersonPhotos' => '{}'];
    public array $buffers = ['ImageHook' => '1'];
    public array $log = [];
    public string $dir;

    protected function GetBuffer(string $name): string
    {
        return $this->buffers[$name] ?? '';
    }

    protected function ReadAttributeString(string $name): string
    {
        return $this->attributes[$name];
    }

    protected function GetIDForIdent(string $ident): int
    {
        return $ident === self::MEDIA_SNAPSHOT && isset($GLOBALS['media'][100]) ? 100 : 0;
    }

    private function readLog(): array
    {
        return $this->log;
    }

    private function logImagePath(int $id): string
    {
        return $this->dir . '/ring_' . $id . '.jpg';
    }

    protected function SendHeader(string $header): void
    {
        [$name, $value] = explode(': ', $header, 2);
        $this->headers[$name] = $value;
    }

    protected function SendStatus(int $code): void
    {
        $this->status = $code;
    }

    /** Hook mit GET-Parametern und optionalem If-None-Match aufrufen; liefert den Rumpf. */
    public function call(array $get, string $ifNoneMatch = ''): string
    {
        $this->headers = [];
        $this->status = 200;
        $_GET = $get;
        $_SERVER['HTTP_IF_NONE_MATCH'] = $ifNoneMatch;
        ob_start();
        $this->ProcessHookData();
        return (string) ob_get_clean();
    }

    public function url(string $key, string $id, string $size, string $version): string
    {
        return $this->imageUrl($key, $id, $size, $version);
    }
}

$fails = 0;
$check = static function (string $what, bool $ok) use (&$fails): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    $fails += $ok ? 0 : 1;
};

// Testbild 800x600
$img = imagecreatetruecolor(800, 600);
imagefill($img, 0, 0, imagecolorallocate($img, 40, 120, 200));
ob_start();
imagejpeg($img, null, 85);
$jpeg = (string) ob_get_clean();

$token = str_repeat('ab', 16);
$p = new Probe();
$p->attributes['ImageHookToken'] = $token;
$p->dir = sys_get_temp_dir() . '/vdm10_hooktest_' . getmypid();
@mkdir($p->dir);
file_put_contents($p->dir . '/ring_42.jpg', $jpeg);
$p->log = [['id' => 42, 'img' => true], ['id' => 43, 'img' => false]];
$GLOBALS['media'] = [100 => [MEDIATYPE_IMAGE, base64_encode($jpeg), 'C1'], 200 => [MEDIATYPE_IMAGE, base64_encode($jpeg), 'P1'], 300 => [2, base64_encode('x'), 'S1']];
$p->attributes['PersonPhotos'] = json_encode(['7' => 200, '8' => 300]);
$snapVersion = 'C11700000000';

// Zugriffsschutz
$p->call(['k' => 'snapshot', 'id' => '0']);
$check('ohne Token 403', $p->status === 403);
$p->call(['k' => 'snapshot', 'id' => '0', 't' => str_repeat('00', 16)]);
$check('falsches Token 403', $p->status === 403);
$p->call(['k' => 'snapshot', 'id' => '0', 't' => ['x']]);
$check('Token als Array 403', $p->status === 403);
$p->buffers['ImageHook'] = '';
$p->call(['k' => 'snapshot', 'id' => '0', 't' => $token]);
$check('Hook nicht angemeldet 403', $p->status === 403);
$check('ohne Hook keine Adresse', $p->url('snapshot', '0', 'full', $snapVersion) === '');
$p->buffers['ImageHook'] = '1';

// Unbekannte Bilder
foreach ([
    'unbekannter Schlüssel'        => ['k' => 'file', 'id' => '../../settings.json'],
    'Klingelbild ohne Bild'        => ['k' => 'ring', 'id' => '43'],
    'Klingelbild nicht im Log'     => ['k' => 'ring', 'id' => '44'],
    'Klingelbild mit Pfad'         => ['k' => 'ring', 'id' => '../42'],
    'Person ohne Foto'             => ['k' => 'person', 'id' => '9'],
    'Person mit Nicht-Bild-Medium' => ['k' => 'person', 'id' => '8'],
    'Person mit ungültiger Nummer' => ['k' => 'person', 'id' => '7/../8'],
] as $what => $get) {
    $p->call($get + ['t' => $token]);
    $check($what . ' 404', $p->status === 404);
}

// Gültige Abrufe
$body = $p->call(['k' => 'snapshot', 'id' => '0', 's' => 'full', 'v' => $snapVersion, 't' => $token]);
$check('Snapshot 200 mit JPEG', $p->status === 200 && $body === $jpeg && $p->headers['Content-Type'] === 'image/jpeg');
$check('aktuelle Version lange cachen', $p->headers['Cache-Control'] === 'private, max-age=31536000, immutable');
$check('nosniff gesetzt', $p->headers['X-Content-Type-Options'] === 'nosniff');
$check('Content-Length stimmt', (int) $p->headers['Content-Length'] === strlen($jpeg));
$etag = $p->headers['ETag'];
$p->call(['k' => 'snapshot', 'id' => '0', 's' => 'full', 'v' => 'alt', 't' => $token]);
$check('alte Version no-cache', $p->status === 200 && $p->headers['Cache-Control'] === 'no-cache');
$body = $p->call(['k' => 'snapshot', 'id' => '0', 's' => 'full', 'v' => $snapVersion, 't' => $token], $etag);
$check('passendes ETag 304 ohne Rumpf', $p->status === 304 && $body === '');

$body = $p->call(['k' => 'ring', 'id' => '42', 's' => 'thumb', 'v' => '42', 't' => $token]);
$size = @getimagesizefromstring($body);
$check('Klingel-Vorschau verkleinert', $p->status === 200 && $size !== false && max($size[0], $size[1]) <= 320 && strlen($body) < strlen($jpeg));
$check('Vorschau hat eigenes ETag', $p->headers['ETag'] !== $etag);

$body = $p->call(['k' => 'person', 'id' => '7', 's' => 'full', 'v' => '200-P1', 't' => $token]);
$check('Personenfoto 200', $p->status === 200 && $body === $jpeg && str_contains($p->headers['Cache-Control'], 'immutable'));

$url = $p->url('ring', '42', 'thumb', '42');
$check('Adresse enthält Token und Instanz-Hook', str_starts_with($url, '/hook/vdm10/30293?') && str_contains($url, 't=' . $token));

array_map('unlink', glob($p->dir . '/*'));
rmdir($p->dir);
exit($fails === 0 ? 0 : 1);
