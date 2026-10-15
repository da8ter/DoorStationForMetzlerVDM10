<?php

declare(strict_types=1);

/**
 * Medienobjekte der VDM10-Instanz: aktueller Snapshot, Snapshot-Archiv und
 * RTSP-Livestream. Wird von VDM10 (module.php) als Trait eingebunden.
 */
trait VDM10MediaTrait
{
    private const MEDIA_SNAPSHOT = 'SNAPSHOT';
    private const MEDIA_LIVE = 'LIVE';
    private const POSITION_SNAPSHOT = 200;
    private const POSITION_LIVE = 190;

    /** Legt Medienobjekte passend zur Konfiguration an bzw. entfernt sie. */
    private function maintainMedia(): void
    {
        $this->mediaId(self::MEDIA_SNAPSHOT, MEDIATYPE_IMAGE, $this->Translate('Snapshot'), self::POSITION_SNAPSHOT);
        $archive = max(0, min(10, $this->ReadPropertyInteger('SnapshotArchive')));
        for ($i = 1; $i <= 10; $i++) {
            $ident = self::MEDIA_SNAPSHOT . '_' . $i;
            $id = (int) @$this->GetIDForIdent($ident);
            if ($i <= $archive) {
                $this->mediaId($ident, MEDIATYPE_IMAGE, sprintf($this->Translate('Snapshot -%d'), $i), self::POSITION_SNAPSHOT + $i);
            } elseif ($id > 0) {
                IPS_DeleteMedia($id, true);
            }
        }
        $live = (int) @$this->GetIDForIdent(self::MEDIA_LIVE);
        if ($this->ReadPropertyBoolean('StreamMedia') && $this->ReadPropertyString('Host') !== '') {
            $live = $this->mediaId(self::MEDIA_LIVE, MEDIATYPE_STREAM, $this->Translate('Live view'), self::POSITION_LIVE);
            $url = $this->rtspUrl();
            if (IPS_GetMedia($live)['MediaFile'] !== $url) {
                IPS_SetMediaFile($live, $url, false);
            }
        } elseif ($live > 0) {
            IPS_DeleteMedia($live, true);
        }
    }

    private function rtspUrl(): string
    {
        return sprintf('rtsp://%s:%s@%s:554/Streaming/Channels/%d',
            rawurlencode($this->ReadPropertyString('Username')), rawurlencode($this->ReadPropertyString('Password')),
            $this->ReadPropertyString('Host'), $this->ReadPropertyInteger('StreamChannel'));
    }

    private function mediaId(string $ident, int $type, string $name, int $position): int
    {
        $id = (int) @$this->GetIDForIdent($ident);
        if ($id > 0) {
            return $id;
        }
        $id = IPS_CreateMedia($type);
        IPS_SetParent($id, $this->InstanceID);
        IPS_SetIdent($id, $ident);
        IPS_SetName($id, $name);
        IPS_SetPosition($id, $position);
        if ($type === MEDIATYPE_IMAGE) {
            IPS_SetMediaCached($id, true);
            IPS_SetMediaFile($id, 'media/vdm10_' . $this->InstanceID . '_' . strtolower($ident) . '.jpg', false);
        }
        return $id;
    }

    /** Neues Bild übernehmen; das bisherige rückt ins Archiv. */
    private function storeSnapshot(string $jpeg): void
    {
        if ($jpeg === '' || !str_starts_with($jpeg, "\xFF\xD8")) {
            return;
        }
        $current = (int) @$this->GetIDForIdent(self::MEDIA_SNAPSHOT);
        if ($current === 0) {
            return;
        }
        $archive = max(0, min(10, $this->ReadPropertyInteger('SnapshotArchive')));
        for ($i = $archive; $i >= 1; $i--) {
            $to = (int) @$this->GetIDForIdent(self::MEDIA_SNAPSHOT . '_' . $i);
            $from = $i === 1 ? $current : (int) @$this->GetIDForIdent(self::MEDIA_SNAPSHOT . '_' . ($i - 1));
            if ($to > 0 && $from > 0 && (int) (IPS_GetMedia($from)['MediaSize'] ?? 0) > 0) {
                $content = IPS_GetMediaContent($from);
                if ($content !== '') {
                    IPS_SetMediaContent($to, $content);
                    IPS_SendMediaEvent($to);
                }
            }
        }
        IPS_SetMediaContent($current, base64_encode($jpeg));
        IPS_SendMediaEvent($current);
        $this->tileChanged();
        $this->SendDebug('Snapshot', sprintf('%d bytes', strlen($jpeg)), 0);
    }
}
