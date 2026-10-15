<?php

declare(strict_types=1);

/**
 * Bilder für die Kachel über einen abgesicherten WebHook (/hook/vdm10/<InstanzID>), Muster wie
 * TileVisu-Bild-Variable: zufälliges Token im Attribut, Prüfung mit hash_equals, Version in der
 * Adresse für langes Caching. Ausgeliefert werden nur der aktuelle Snapshot, Klingelbilder aus dem
 * eigenen Log und die zugeordneten Personenfotos – nie Pfade aus Parametern.
 * Wird von VDM10 (module.php) als Trait eingebunden.
 */
trait VDM10ImageHookTrait
{
    private const IMAGE_HOOK = 'vdm10';
    private const THUMB_SIZE = 160;

    /** In ApplyChanges(): Hook anmelden, Token sicherstellen. */
    private function registerImageHook(): void
    {
        $this->SetBuffer('ImageHook', $this->RegisterHook(self::IMAGE_HOOK . '/' . $this->InstanceID) ? '1' : '');
        if ($this->GetBuffer('ImageHook') === '1' && $this->ReadAttributeString('ImageHookToken') === '') {
            $this->WriteAttributeString('ImageHookToken', bin2hex(random_bytes(16)));
        }
    }

    /** Adresse eines Bildes für die Kachel, '' wenn der Hook nicht aktiv ist. */
    private function imageUrl(string $key, string $id, string $size, string $version): string
    {
        $token = $this->GetBuffer('ImageHook') === '1' ? $this->ReadAttributeString('ImageHookToken') : '';
        if ($token === '' || $version === '') {
            return '';
        }
        return '/hook/' . self::IMAGE_HOOK . '/' . $this->InstanceID . '?k=' . rawurlencode($key) . '&id=' . rawurlencode($id)
            . '&s=' . $size . '&v=' . rawurlencode($version) . '&t=' . $token;
    }

    /** Version des aktuellen Snapshots ('' = keiner vorhanden). */
    private function snapshotVersion(): string
    {
        $media = (int) @$this->GetIDForIdent(self::MEDIA_SNAPSHOT);
        if ($media === 0) {
            return '';
        }
        $m = IPS_GetMedia($media);
        return (int) ($m['MediaSize'] ?? 0) > 0 ? (string) ($m['MediaCRC'] ?? '') . (string) ($m['MediaUpdated'] ?? '') : '';
    }

    /** Version eines Personenfotos ('' = keins zugeordnet oder kein Bild). */
    private function personPhotoVersion(string $employeeNo): string
    {
        $media = $this->personPhotoId($employeeNo);
        if ($media === 0) {
            return '';
        }
        $m = IPS_GetMedia($media);
        return (int) ($m['MediaSize'] ?? 0) > 0 ? $media . '-' . (string) ($m['MediaCRC'] ?? '') : '';
    }

    /** Medien-ID des Fotos einer Person, 0 wenn keins oder kein Bild-Medienobjekt mehr. */
    private function personPhotoId(string $employeeNo): int
    {
        $media = (int) ($this->personPhotos()[$employeeNo] ?? 0);
        return $media > 0 && IPS_MediaExists($media) && IPS_GetMedia($media)['MediaType'] === MEDIATYPE_IMAGE ? $media : 0;
    }

    /** @return array<string,int> Personennummer => Medien-ID */
    private function personPhotos(): array
    {
        $map = json_decode($this->ReadAttributeString('PersonPhotos'), true);
        return is_array($map) ? $map : [];
    }

    /** Foto einer Person zuordnen (0 = entfernen) und die Referenz pflegen. */
    private function setPersonPhoto(string $employeeNo, int $mediaId): void
    {
        $map = $this->personPhotos();
        $old = (int) ($map[$employeeNo] ?? 0);
        if ($old === $mediaId) {
            return;
        }
        if ($mediaId > 0 && (!IPS_MediaExists($mediaId) || IPS_GetMedia($mediaId)['MediaType'] !== MEDIATYPE_IMAGE)) {
            throw new InvalidArgumentException($this->Translate('Please select an image media object'));
        }
        unset($map[$employeeNo]);
        if ($mediaId > 0) {
            $map[$employeeNo] = $mediaId;
        }
        $this->WriteAttributeString('PersonPhotos', (string) json_encode($map));
        if ($old > 0 && !in_array($old, $map, true)) {
            $this->UnregisterReference($old);
        }
        if ($mediaId > 0) {
            $this->RegisterReference($mediaId);
        }
        $this->tileChanged();
    }

    /** /hook/vdm10/<ID>?k=snapshot|ring|person&id=…&s=full|thumb&v=…&t=<Token> */
    protected function ProcessHookData(): void
    {
        $token = $this->GetBuffer('ImageHook') === '1' ? $this->ReadAttributeString('ImageHookToken') : '';
        $given = isset($_GET['t']) && is_string($_GET['t']) ? $_GET['t'] : '';
        if ($token === '' || !hash_equals($token, $given)) {
            $this->SendStatus(403);
            return;
        }
        $key = (string) ($_GET['k'] ?? '');
        $id = (string) ($_GET['id'] ?? '');
        [$jpeg, $version] = $this->hookImage($key, $id);
        if ($jpeg === '') {
            $this->SendStatus(404);
            return;
        }
        $thumb = ($_GET['s'] ?? '') === 'thumb';
        $etag = substr(hash('sha256', $version . ($thumb ? 't' : 'f')), 0, 16);
        $current = (string) ($_GET['v'] ?? '') === $version;
        $this->SendHeader('Cache-Control: ' . ($current ? 'private, max-age=31536000, immutable' : 'no-cache'));
        $this->SendHeader('ETag: "' . $etag . '"');
        $this->SendHeader('X-Content-Type-Options: nosniff');
        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === '"' . $etag . '"') {
            $this->SendStatus(304);
            return;
        }
        $bytes = $thumb ? $this->thumbnail($jpeg) : $jpeg;
        $this->SendHeader('Content-Type: image/jpeg');
        $this->SendHeader('Content-Length: ' . strlen($bytes));
        echo $bytes;
    }

    /** @return array{0:string,1:string} JPEG-Daten und Version, ['', ''] wenn unbekannt */
    private function hookImage(string $key, string $id): array
    {
        if ($key === 'snapshot') {
            $media = (int) @$this->GetIDForIdent(self::MEDIA_SNAPSHOT);
            $version = $this->snapshotVersion();
            return $version === '' ? ['', ''] : [(string) base64_decode(IPS_GetMediaContent($media), true), $version];
        }
        if ($key === 'ring' && ctype_digit($id)) {
            foreach ($this->readLog() as $entry) {
                if ((string) $entry['id'] === $id && !empty($entry['img']) && is_file($this->logImagePath((int) $id))) {
                    return [(string) file_get_contents($this->logImagePath((int) $id)), $id];
                }
            }
            return ['', ''];
        }
        if ($key === 'person' && preg_match('/^[A-Za-z0-9_-]{1,32}$/', $id)) {
            $media = $this->personPhotoId($id);
            $version = $this->personPhotoVersion($id);
            return $media === 0 || $version === '' ? ['', ''] : [(string) base64_decode(IPS_GetMediaContent($media), true), $version];
        }
        return ['', ''];
    }

    /** Verkleinertes JPEG (Kante ≤ THUMB_SIZE); ohne GD das Original. */
    private function thumbnail(string $image): string
    {
        if (!function_exists('imagecreatefromstring')) {
            return $image;
        }
        $src = @imagecreatefromstring($image);
        if ($src === false) {
            return $image;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, self::THUMB_SIZE * 2 / max($w, $h)); // doppelte Auflösung für scharfe Anzeige
        $dst = imagescale($src, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
        ob_start();
        imagejpeg($dst !== false ? $dst : $src, null, 75);
        return (string) ob_get_clean();
    }

    // Eigene Methoden für Kopfzeilen und Status, damit Tests den Hook ohne Webserver prüfen können.
    protected function SendHeader(string $header): void
    {
        header($header);
    }

    protected function SendStatus(int $code): void
    {
        http_response_code($code);
    }
}
