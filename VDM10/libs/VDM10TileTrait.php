<?php

declare(strict_types=1);

/**
 * HTML-Kachel der Türstation: Seite ausliefern (Gerüst module.html + assets, wie der Apple-Music-
 * Player), Zustand bauen, nur bei Änderung pushen (gebündelt über den Einmal-Timer TilePush).
 * Bilder kommen nie als Daten in die Kachel, nur als Adressen auf den abgesicherten Hook.
 * Wird von VDM10 (module.php) als Trait eingebunden.
 */
trait VDM10TileTrait
{
    private const TILE_PUSH_MS = 300;
    private const TILE_ACCESS_PERSONS = 5;

    /** Texte der Kachel (englisch; Übersetzung über locale.json). */
    private const TILE_TEXTS = [
        'Online', 'Offline', 'SIP', 'Stream', 'Live view', 'New picture', 'No picture yet', 'Someone is ringing',
        'Reject', 'Open door', 'Hold to open', 'Opening…', 'Door %d', 'Permanently open', 'Ring tone outside', 'Motion detection',
        'Recent access', 'No access yet', 'Unknown', 'Events', 'No events yet', 'just now', '%d min ago', '%d h ago',
        'Yesterday', 'Fingerprint', 'Card', 'PIN', 'Face', 'Open', 'Closed', 'Alarm', 'Close',
        'Last ring', 'Never', 'Pictures', 'Current picture', 'Previous', 'Next', 'Modules',
    ];

    /** In ApplyChanges(): Wechsel der eigenen Variablen beobachten (Log und Kachel). */
    private function registerTileMessages(): void
    {
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $child) {
            if (IPS_VariableExists($child)) {
                $this->RegisterMessage($child, VM_UPDATE);
            }
        }
        $this->SetBuffer('TileHash', '');
        $this->tileChanged();
    }

    /** Kachel-Push vormerken; mehrere Änderungen kurz hintereinander ergeben einen Push. */
    private function tileChanged(): void
    {
        $this->SetTimerInterval('TilePush', self::TILE_PUSH_MS);
    }

    public function TilePush(): void
    {
        $this->SetTimerInterval('TilePush', 0);
        $state = $this->tileState();
        $hash = md5((string) json_encode($state));
        if ($hash === $this->GetBuffer('TileHash')) {
            return;
        }
        $this->SetBuffer('TileHash', $hash);
        $this->UpdateVisualizationValue((string) json_encode(['type' => 'state', 'now' => time()] + $state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function GetVisualizationTile(): string
    {
        $texts = [];
        foreach (self::TILE_TEXTS as $text) {
            $texts[$text] = $this->Translate($text);
        }
        $config = ['texts' => $texts, 'state' => ['type' => 'state', 'now' => time()] + $this->tileState()];
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $asset = static fn (string $file): string => (string) file_get_contents(__DIR__ . '/../assets/' . $file);
        return strtr((string) file_get_contents(__DIR__ . '/../module.html'), [
            '/*STYLE*/'      => $asset('tile.css'),
            '/*CONFIG*/null' => (string) json_encode($config, $flags),
            '/*ICONS*/'      => $asset('icons.js'),
            '/*SCROLL*/'     => $asset('scroll.js'),
            '/*TILE*/'       => $asset('tile.js'),
        ]);
    }

    /** Aktionen, die nur die Kachel auslöst; false = Ident gehört nicht hierher. */
    private function tileAction(string $Ident, mixed $Value): bool
    {
        if ($Ident !== 'TileSnapshot') {
            return false;
        }
        if (!$this->TakeSnapshot()) {
            throw new RuntimeException($this->Translate('No picture captured'));
        }
        return true;
    }

    /** @return array<string,mixed> */
    private function tileState(): array
    {
        $value = fn (string $ident, mixed $default): mixed => @$this->GetIDForIdent($ident) ? $this->GetValue($ident) : $default;
        $info = json_decode($this->ReadAttributeString('DeviceInfo'), true);
        $doors = [];
        for ($no = 1; $no <= max(1, min(2, $this->ReadPropertyInteger('DoorCount'))); $no++) {
            $doors[] = ['no' => $no, 'hold' => (bool) $value('DOOR' . $no . '_HOLD', false),
                'contact' => $value('DOOR' . $no . '_CONTACT', null), 'alarm' => $value('DOOR' . $no . '_ALARM', null)];
        }
        $snapshot = $this->snapshotVersion();
        $media = (int) @$this->GetIDForIdent(self::MEDIA_SNAPSHOT);
        $live = (int) @$this->GetIDForIdent(self::MEDIA_LIVE);
        $log = $this->readLog();
        return [
            'name'      => (string) ($info['deviceName'] ?? '') ?: IPS_GetName($this->InstanceID),
            'online'    => (bool) $value('ONLINE', false),
            'stream'    => (bool) $value('STREAM_CONNECTED', false),
            'sip'       => (string) $value('SIP_STATUS', ''),
            'call'      => (int) $value('CALL_STATUS', 0),
            'ringing'   => (bool) $value('DOORBELL', false),
            'lastRing'  => (int) $value('LAST_RING', 0),
            'doors'     => $doors,
            'holdAllowed' => $this->ReadPropertyBoolean('HoldOpenEnabled'),
            'ring'      => (bool) $value('RING_ENABLED', true),
            'motionDet' => (bool) $value('MOTION_DETECTION', false),
            'motion'    => (bool) $value('MOTION', false),
            'tamper'    => (bool) $value('TAMPER', false),
            'snapshot'  => $snapshot === '' ? null : ['url' => $this->imageUrl('snapshot', '0', 'full', $snapshot),
                'thumb' => $this->imageUrl('snapshot', '0', 'thumb', $snapshot), 'ts' => (int) (IPS_GetMedia($media)['MediaUpdated'] ?? 0)],
            'live'      => $live,
            'modules'   => $this->tileModules(),
            'readers'   => $this->credentials(),
            'access'    => $this->tileAccess($log),
            'log'       => array_map(fn (array $e): array => [
                'id' => $e['id'], 'ts' => $e['ts'], 'kind' => $e['kind'], 'text' => $e['text'], 'name' => $e['name'] ?? '',
                'method' => $e['method'] ?? '', 'door' => $e['door'] ?? 0,
                'thumb' => !empty($e['img']) ? $this->imageUrl('ring', (string) $e['id'], 'thumb', (string) $e['id']) : '',
                'full'  => !empty($e['img']) ? $this->imageUrl('ring', (string) $e['id'], 'full', (string) $e['id']) : '',
            ], $log),
        ];
    }

    /**
     * Die letzten Zutritte verschiedener Personen. Aufeinanderfolgende abgelehnte Versuche
     * bilden einen Eintrag mit Anzahl (Badge in der Kachel).
     */
    private function tileAccess(array $log): array
    {
        $seen = [];
        $out = [];
        $lastDenied = false;
        foreach ($log as $e) {
            if (!in_array($e['kind'], ['access', 'access_denied'], true)) {
                continue;
            }
            $denied = $e['kind'] === 'access_denied';
            if ($denied && $lastDenied) {
                $out[count($out) - 1]['count']++;
                continue;
            }
            $lastDenied = $denied;
            $emp = (string) ($e['emp'] ?? '');
            $key = !$denied ? ($emp !== '' ? 'p' . $emp : 'n' . ($e['name'] ?? '')) : 'd' . $e['id'];
            if (isset($seen[$key])) {
                $lastDenied = false; // auch eine schon gezeigte Person trennt Fehlversuche
                continue;
            }
            if (count($out) >= self::TILE_ACCESS_PERSONS) {
                break; // erst hier, damit Fehlversuche des letzten Eintrags noch mitzählen
            }
            $seen[$key] = true;
            $photo = $emp !== '' ? $this->personPhotoVersion($emp) : '';
            $out[] = ['name' => (string) ($e['name'] ?? ''), 'ts' => $e['ts'], 'method' => $e['method'] ?? '',
                'denied' => $denied, 'count' => 1,
                'full' => !empty($e['img']) ? $this->imageUrl('ring', (string) $e['id'], 'full', (string) $e['id']) : '',
                'thumb' => !empty($e['img']) ? $this->imageUrl('ring', (string) $e['id'], 'thumb', (string) $e['id']) : '',
                'photo' => $photo !== '' ? $this->imageUrl('person', $emp, 'thumb', $photo) : ''];
        }
        return $out;
    }
}
