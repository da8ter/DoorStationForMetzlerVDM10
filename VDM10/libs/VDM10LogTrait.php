<?php

declare(strict_types=1);

/**
 * Ereignis-Log der Türstation für die Kachel: die letzten 50 Ereignisse im Attribut `EventLog`
 * (nur Text), Bilder als Dateien unter media/vdm10_<InstanzID>/ (je die letzten 10 Klingel- und
 * Zutrittsbilder).
 * Statuswechsel kommen über VM_UPDATE der eigenen Variablen, Klingeln und Zutritte direkt aus
 * der Ereignisverarbeitung. Wird von VDM10 (module.php) als Trait eingebunden.
 */
trait VDM10LogTrait
{
    private const LOG_SIZE = 50;
    private const LOG_IMAGES = 10; // je Gruppe: Klingeln und Zutritte

    /** Variablen, deren Wechsel ins Log gehören: Ident => [Art bei true, Art bei false] (null = nicht loggen). */
    private const LOG_ON_CHANGE = [
        'ONLINE'           => ['online', 'offline'],
        'DOOR1_HOLD'       => ['hold_on', 'hold_off'],
        'DOOR2_HOLD'       => ['hold_on', 'hold_off'],
        'MOTION'           => ['motion', null],
        'TAMPER'           => ['tamper', null],
        'DOOR1_CONTACT'    => ['door_open', 'door_closed'],
        'DOOR2_CONTACT'    => ['door_open', 'door_closed'],
        'DOOR1_ALARM'      => ['door_alarm', null],
        'DOOR2_ALARM'      => ['door_alarm', null],
        'SECURITY_MODULE'  => ['sm_online', 'sm_offline'],
        'DND'              => ['dnd_on', 'dnd_off'],
    ];

    /** Texte je Art (englisch, übersetzt beim Eintragen). */
    private const LOG_TEXTS = [
        'ring' => 'Doorbell', 'unlock' => 'Door opener activated', 'hold_on' => 'Door permanently open', 'hold_off' => 'Door back to normal',
        'access' => 'Access', 'access_denied' => 'Access denied', 'motion' => 'Motion', 'tamper' => 'Tamper alarm',
        'door_open' => 'Door opened', 'door_closed' => 'Door closed', 'door_alarm' => 'Door alarm',
        'online' => 'Door station online', 'offline' => 'Door station offline', 'sip_on' => 'SIP registered',
        'sip_off' => 'SIP registration lost', 'sm_online' => 'Security module online', 'sm_offline' => 'Security module offline',
        'module_online' => 'Extension module online', 'module_offline' => 'Extension module offline',
        'clock_set' => 'Clock of the door station set', 'dnd_on' => 'Do not disturb on', 'dnd_off' => 'Do not disturb off',
    ];

    /** @return array<int, array<string,mixed>> Log, neuester Eintrag zuerst */
    private function readLog(): array
    {
        $log = json_decode($this->ReadAttributeString('EventLog'), true);
        return is_array($log) ? $log : [];
    }

    /** Neuen Eintrag vorn anfügen; liefert die ID. */
    private function logEvent(string $kind, array $extra = []): int
    {
        $log = $this->readLog();
        $id = (int) round(microtime(true) * 1000);
        if ($log !== [] && $id <= (int) $log[0]['id']) {
            $id = (int) $log[0]['id'] + 1;
        }
        $door = (int) ($extra['door'] ?? 0);
        $text = $this->Translate(self::LOG_TEXTS[$kind] ?? $kind);
        if ($door > 0 && $this->ReadPropertyInteger('DoorCount') >= 2) {
            $text .= ' · ' . sprintf($this->Translate('Door %d'), $door);
        }
        array_unshift($log, ['id' => $id, 'ts' => time(), 'kind' => $kind, 'text' => $text] + $extra);
        foreach (array_slice($log, self::LOG_SIZE) as $old) {
            $this->deleteLogImage((int) $old['id']);
        }
        $this->WriteAttributeString('EventLog', (string) json_encode(array_slice($log, 0, self::LOG_SIZE), JSON_UNESCAPED_UNICODE));
        $this->tileChanged();
        $this->notifyEvent($kind, $text, $extra);
        return $id;
    }

    /** Klingeln mit dem aktuellen Snapshot ins Log. */
    private function logRing(string $source, string $button = ''): void
    {
        $media = (int) @$this->GetIDForIdent(self::MEDIA_SNAPSHOT);
        $jpeg = $media > 0 ? (string) base64_decode(IPS_GetMediaContent($media), true) : '';
        $id = $this->logEvent('ring', ['source' => $source] + ($button !== '' ? ['name' => $button] : []));
        $this->attachLogImage($id, $jpeg);
    }

    /** Bild an einen Log-Eintrag hängen; je Gruppe (Klingeln, Zutritte) bleiben die letzten 10. */
    private function attachLogImage(int $id, string $jpeg): void
    {
        if ($jpeg === '' || !str_starts_with($jpeg, "\xFF\xD8") || !$this->ensureLogDir()
            || file_put_contents($this->logImagePath($id), $jpeg) === false) {
            return;
        }
        $log = $this->readLog();
        $kept = ['ring' => 0, 'access' => 0];
        foreach ($log as &$entry) {
            if ((int) $entry['id'] === $id) {
                $entry['img'] = true;
            }
            $group = $entry['kind'] === 'ring' ? 'ring' : 'access';
            if (!empty($entry['img']) && ++$kept[$group] > self::LOG_IMAGES) {
                $this->deleteLogImage((int) $entry['id']);
                $entry['img'] = false;
            }
        }
        unset($entry);
        $this->WriteAttributeString('EventLog', (string) json_encode($log, JSON_UNESCAPED_UNICODE));
        $this->tileChanged();
    }

    /** Taste nachträglich am letzten Klingel-Eintrag vermerken (callerInfo kommt nach dem Tastendruck). */
    private function annotateLastRing(string $name): void
    {
        $log = $this->readLog();
        if ($log === [] || $log[0]['kind'] !== 'ring' || time() - (int) $log[0]['ts'] > 60 || ($log[0]['name'] ?? '') !== '') {
            return;
        }
        $log[0]['name'] = $name;
        $this->WriteAttributeString('EventLog', (string) json_encode($log, JSON_UNESCAPED_UNICODE));
        $this->tileChanged();
    }

    /** Zutritt mit Person, Methode und (falls aufgenommen) Bild ins Log. */
    private function logAccess(array $e, string $name, string $employeeNo, string $jpeg = ''): void
    {
        $id = $this->logEvent($e['kind'] === VDM10Events::KIND_ACCESS_OK ? 'access' : 'access_denied', [
            'name' => $name, 'emp' => $employeeNo, 'method' => VDM10Events::accessMethod($e['minor']), 'door' => $e['door'],
        ]);
        $this->attachLogImage($id, $jpeg);
    }

    /** VM_UPDATE einer eigenen Variable: Wechsel ins Log, Kachel aktualisieren. */
    private function logVariableChange(int $variableId, array $data): void
    {
        $this->tileChanged();
        if (!($data[1] ?? false)) {
            return; // Wert unverändert
        }
        $ident = IPS_GetObject($variableId)['ObjectIdent'];
        if ($ident === 'SIP_STATUS') {
            $this->logEvent($data[0] === 'connect' ? 'sip_on' : 'sip_off');
            return;
        }
        if (str_starts_with($ident, 'SUBMODULE_')) {
            $this->logEvent($data[0] ? 'module_online' : 'module_offline', ['name' => IPS_GetName($variableId)]);
            return;
        }
        if (!isset(self::LOG_ON_CHANGE[$ident])) {
            return;
        }
        $kind = self::LOG_ON_CHANGE[$ident][$data[0] ? 0 : 1];
        if ($kind !== null) {
            $door = preg_match('/^DOOR(\d)_/', $ident, $m) ? (int) $m[1] : 0;
            $this->logEvent($kind, $door > 0 ? ['door' => $door] : []);
        }
    }

    private function logDir(): string
    {
        return IPS_GetKernelDir() . 'media' . DIRECTORY_SEPARATOR . 'vdm10_' . $this->InstanceID;
    }

    private function logImagePath(int $id): string
    {
        return $this->logDir() . DIRECTORY_SEPARATOR . 'ring_' . $id . '.jpg';
    }

    private function ensureLogDir(): bool
    {
        return is_dir($this->logDir()) || @mkdir($this->logDir(), 0750, true);
    }

    private function deleteLogImage(int $id): void
    {
        $path = $this->logImagePath($id);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** In Destroy(): Bilddateien und Ordner entfernen – nur wenn die Instanz gelöscht wird, nicht beim Modul-Update. */
    private function removeLogImages(): void
    {
        if (IPS_InstanceExists($this->InstanceID)) {
            return;
        }
        foreach (glob($this->logDir() . DIRECTORY_SEPARATOR . 'ring_*.jpg') ?: [] as $file) {
            @unlink($file);
        }
        if (is_dir($this->logDir())) {
            @rmdir($this->logDir());
        }
    }
}
