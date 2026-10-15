<?php

declare(strict_types=1);

foreach (['VDM10Isapi', 'VDM10Events', 'VDM10MediaTrait', 'VDM10StreamTrait', 'VDM10PersonsTrait', 'VDM10PersonsUITrait', 'VDM10SchedulesTrait',
    'VDM10SchedulesUITrait', 'VDM10ControlsTrait', 'VDM10DeviceFormTrait', 'VDM10SecurityModuleTrait', 'VDM10LogTrait', 'VDM10ImageHookTrait', 'VDM10TileTrait',
    'VDM10SubModules', 'VDM10SubModulesTrait', 'VDM10SubModulesUITrait', 'VDM10CallTimesTrait', 'VDM10NotifyTrait', 'VDM10DisplayTextTrait'] as $lib) {
    require_once __DIR__ . '/libs/' . $lib . '.php';
}

/**
 * Metzler VDM10 Video-Türstation (Hikvision DS-KD8003-Basis) über ISAPI.
 *
 * Ereignisse kommen über den ISAPI-Alert-Stream, den ein Client Socket (Parent)
 * dauerhaft offen hält; als Rückfallebene fragt ein Timer den Rufstatus ab.
 * Befehle (Türöffner, Rufsteuerung, Snapshot, Neustart) gehen per curl.
 */
class VDM10 extends IPSModuleStrict
{
    use VDM10MediaTrait;
    use VDM10StreamTrait;
    use VDM10PersonsTrait;
    use VDM10PersonsUITrait;
    use VDM10SchedulesTrait;
    use VDM10SchedulesUITrait;
    use VDM10ControlsTrait;
    use VDM10DeviceFormTrait;
    use VDM10SecurityModuleTrait;
    use VDM10LogTrait;
    use VDM10ImageHookTrait;
    use VDM10TileTrait;
    use VDM10SubModulesTrait;
    use VDM10SubModulesUITrait;
    use VDM10CallTimesTrait;
    use VDM10NotifyTrait;
    use VDM10DisplayTextTrait;

    private const CLIENT_SOCKET_GUID = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
    private const SOCKET_RX = '{018EF6B5-AB94-40C6-AA53-46943E824ACF}';
    private const SOCKET_TX = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';
    private const PRESENTATION_DATE_TIME = '{497C4845-27FA-6E4F-AE37-5D951D3BDBF9}';

    private const STATUS_NO_HOST = 104;
    private const STATUS_AUTH_FAILED = 201;
    private const STATUS_UNREACHABLE = 202;

    private const CALL_IDLE = 0;
    private const CALL_RING = 1;
    private const CALL_ON_CALL = 2;
    private const CTRL_NONE = 0;
    private const CTRL_ANSWER = 1;
    private const CTRL_REJECT = 2;
    private const CTRL_HANGUP = 3;
    private const DOOR_PULSE_MS = 3000;
    // Ein Besuch: Klingelmeldungen (Rufstatus, ggf. Stream) innerhalb dieser Zeit zählen als dasselbe Klingeln;
    // jede Folgemeldung verlängert das Fenster.
    private const RING_DEDUPE_SECONDS = 20;

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 80);
        $this->RegisterPropertyBoolean('UseHttps', false);
        $this->RegisterPropertyString('Username', 'admin');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyBoolean('StreamEnabled', true);
        $this->RegisterPropertyInteger('PollInterval', 3);
        $this->RegisterPropertyInteger('RingTimeout', 30);
        $this->RegisterPropertyInteger('MotionReset', 10);
        $this->RegisterPropertyInteger('DoorCount', 1);
        $this->registerSecurityModuleProperties();
        $this->registerSubModuleProperties();
        $this->RegisterPropertyBoolean('SnapshotOnRing', true);
        $this->RegisterPropertyBoolean('SnapshotOnAccess', true);
        $this->RegisterPropertyBoolean('SnapshotOnMotion', true);
        $this->RegisterPropertyInteger('SnapshotArchive', 3);
        $this->RegisterPropertyInteger('SnapshotChannel', 101);
        $this->RegisterPropertyBoolean('StreamMedia', true);
        $this->RegisterPropertyInteger('StreamChannel', 102);

        $this->RegisterAttributeString('DeviceInfo', '{}');
        $this->RegisterAttributeString('LastEventTime', '');

        $option = fn (int $v, string $c, string $icon, int $color): array => ['Value' => $v, 'Caption' => $this->Translate($c), 'IconValue' => $icon, 'IconActive' => true, 'Color' => $color];
        // Nur lesbar: Aufzählung braucht eine Aktion, deshalb Wertanzeige mit Intervallen je Wert.
        $state = fn (int $v, string $caption, string $icon, int $color): array => [
            'IntervalMinValue' => $v, 'IntervalMaxValue' => $v, 'ConversionFactor' => 1,
            'ConstantActive' => true, 'ConstantValue' => $this->Translate($caption),
            'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '',
            'DigitsActive' => false, 'DigitsValue' => 0,
            'IconActive' => true, 'IconValue' => $icon,
            'ColorActive' => $color >= 0, 'ColorValue' => $color,
            'ContentColorActive' => false, 'ContentColorValue' => -1,
        ];
        $this->RegisterVariableInteger('CALL_STATUS', $this->Translate('Call status'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Telephone', 'DIGITS' => 0,
            'INTERVALS_ACTIVE' => true,
            'INTERVALS' => json_encode([
                $state(self::CALL_IDLE, 'Idle', 'Telephone', -1),
                $state(self::CALL_RING, 'Ringing', 'Alert', 0xFF8000),
                $state(self::CALL_ON_CALL, 'Talking', 'Talk', 0x00C000),
            ], JSON_UNESCAPED_UNICODE),
        ], 10);
        $this->RegisterVariableBoolean('DOORBELL', $this->Translate('Doorbell'), $this->boolPresentation('Alert', 'Quiet', 'Ringing', 0x808080, 0xFF8000), 20);
        $this->RegisterVariableInteger('LAST_RING', $this->Translate('Last ring'), [
            'PRESENTATION' => self::PRESENTATION_DATE_TIME, 'DATE' => 1, 'TIME' => 2, 'MONTH_TEXT' => false, 'DAY_OF_THE_WEEK' => false,
        ], 30);
        $this->RegisterVariableBoolean('DOOR1', $this->Translate('Door opener 1'), $this->doorPresentation(), 40);
        $this->EnableAction('DOOR1');
        $this->RegisterVariableInteger('CALL_CONTROL', $this->Translate('Call control'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION, 'ICON' => 'Telephone',
            'OPTIONS' => json_encode([
                $option(self::CTRL_NONE, '-', 'Telephone', 0x808080),
                $option(self::CTRL_ANSWER, 'Answer', 'Talk', 0x00C000),
                $option(self::CTRL_REJECT, 'Reject', 'Cross', 0xFF0000),
                $option(self::CTRL_HANGUP, 'Hang up', 'Telephone', 0xFF8000),
            ], JSON_UNESCAPED_UNICODE),
        ], 60);
        $this->EnableAction('CALL_CONTROL');
        $this->RegisterVariableBoolean('MOTION', $this->Translate('Motion'), $this->boolPresentation('Motion', 'No motion', 'Motion', 0x808080, 0xFF8000), 70);
        $this->RegisterVariableBoolean('TAMPER', $this->Translate('Tamper'), $this->boolPresentation('Warning', 'OK', 'Tamper', 0x00C000, 0xFF0000), 80);
        $this->RegisterVariableString('LAST_ACCESS', $this->Translate('Last access'), ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Key'], 90);
        $this->RegisterVariableInteger('LAST_ACCESS_TIME', $this->Translate('Last access time'), [
            'PRESENTATION' => self::PRESENTATION_DATE_TIME, 'DATE' => 1, 'TIME' => 2, 'MONTH_TEXT' => false, 'DAY_OF_THE_WEEK' => false,
        ], 100);
        $this->RegisterVariableString('LAST_EVENT', $this->Translate('Last event'), ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Information'], 110);
        $this->RegisterVariableBoolean('ONLINE', $this->Translate('Door station'), $this->boolPresentation('Network', 'Offline', 'Online', 0xFF0000, 0x00C000), 120);
        $this->RegisterVariableBoolean('STREAM_CONNECTED', $this->Translate('Event stream'), $this->boolPresentation('Network', 'Disconnected', 'Connected', 0x808080, 0x00C000), 130);

        $this->registerControlVariables();
        $this->RegisterTimer('Poll', 0, 'VDM10_Poll($_IPS[\'TARGET\']);');
        $this->RegisterTimer('Watchdog', 0, 'VDM10_Watchdog($_IPS[\'TARGET\']);');
        $this->RegisterTimer('RingReset', 0, 'VDM10_ResetRing($_IPS[\'TARGET\']);');
        $this->RegisterTimer('MotionReset', 0, 'VDM10_ResetMotion($_IPS[\'TARGET\']);');
        $this->RegisterTimer('DoorReset', 0, 'VDM10_ResetDoors($_IPS[\'TARGET\']);');
        $this->RegisterTimer('TilePush', 0, 'VDM10_TilePush($_IPS[\'TARGET\']);');
        $this->RegisterAttributeString('EventLog', '[]');
        $this->RegisterAttributeString('ImageHookToken', '');
        $this->RegisterAttributeString('PersonPhotos', '{}');
        $this->SetVisualizationType(defined('INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN') ? INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN : 1);
    }

    public function Destroy(): void
    {
        $this->removeLogImages();
        parent::Destroy();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }
        foreach ($this->GetMessageList() as $sender => $messages) {
            foreach ($messages as $message) {
                if ($message === IM_CHANGESTATUS || $message === VM_UPDATE) {
                    $this->UnregisterMessage((int) $sender, $message);
                }
            }
        }
        $this->MaintainVariable('DOOR2', $this->Translate('Door opener 2'), VARIABLETYPE_BOOLEAN, $this->doorPresentation(), 50, $this->ReadPropertyInteger('DoorCount') >= 2);
        if ($this->ReadPropertyInteger('DoorCount') >= 2) {
            $this->EnableAction('DOOR2');
        }
        $this->maintainMedia();
        $this->maintainSecurityModuleVariables();
        $this->registerImageHook();

        $host = $this->ReadPropertyString('Host');
        $this->SetSummary($host);
        if ($host === '') {
            $this->maintainControlVariables(false);
            $this->maintainSubModules(false);
            $this->SetTimerInterval('Poll', 0);
            $this->SetTimerInterval('Watchdog', 0);
            $this->SetStatus(self::STATUS_NO_HOST);
            $this->registerTileMessages();
            return;
        }
        // Socket nachträglich verbunden oder getrennt (Konsole): neu einrichten
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);
        $io = $this->ioId();
        if ($io > 0) {
            $this->RegisterMessage($io, IM_CHANGESTATUS);
        }
        $this->refreshDeviceInfo();
        $this->maintainControlVariables($this->GetStatus() === IS_ACTIVE);
        $this->maintainSubModules($this->GetStatus() === IS_ACTIVE);
        $this->SetTimerInterval('Poll', max(0, $this->ReadPropertyInteger('PollInterval')) * 1000);
        $this->SetTimerInterval('Watchdog', 30000);
        if ($this->ReadPropertyBoolean('StreamEnabled') && $this->HasActiveParent()) {
            $this->startStream();
        } else {
            $this->streamDown();
        }
        $this->registerTileMessages();
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        return (string) json_encode($this->fillCallTimesForm($this->fillSubModulesForm($this->fillDeviceForm($this->fillPersonsForm($form)))));
    }

    /** Beim Anlegen einen eigenen Client Socket anfordern (Module Strict: Ersatz für RequireParent). */
    public function GetCompatibleParents(): string
    {
        return (string) json_encode(['type' => 'require', 'moduleIDs' => [self::CLIENT_SOCKET_GUID]]);
    }

    public function GetConfigurationForParent(): string
    {
        return (string) json_encode([
            'Host' => $this->ReadPropertyString('Host'), 'Port' => $this->ReadPropertyInteger('Port'),
            'Open' => $this->ReadPropertyBoolean('StreamEnabled') && $this->ReadPropertyString('Host') !== '',
        ]);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED || $Message === FM_CONNECT || $Message === FM_DISCONNECT) {
            $this->ApplyChanges();
            return;
        }
        if ($Message === VM_UPDATE) {
            $this->logVariableChange($SenderID, $Data);
            return;
        }
        if ($Message === IM_CHANGESTATUS && $SenderID === $this->ioId()) {
            if ((int) ($Data[0] ?? 0) === IS_ACTIVE) {
                $this->startStream();
            } else {
                $this->streamDown();
            }
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'DOOR1':
            case 'DOOR2':
                if ((bool) $Value) {
                    $this->UnlockDoor((int) substr($Ident, 4));
                }
                break;
            case 'CALL_CONTROL':
                $cmd = [self::CTRL_ANSWER => 'answer', self::CTRL_REJECT => 'reject', self::CTRL_HANGUP => 'hangUp'][(int) $Value] ?? '';
                if ($cmd !== '' && !$this->isapi()->callSignal($cmd)) {
                    throw new Exception($this->Translate('Call control failed'));
                }
                $this->SetValue('CALL_CONTROL', self::CTRL_NONE);
                break;
            default:
                if (!$this->controlAction($Ident, $Value) && !$this->tileAction($Ident, $Value) && !$this->subModuleAction($Ident, $Value)
                    && !$this->notifyAction($Ident, $Value) && !$this->displayTextAction($Ident, $Value)) {
                    throw new Exception('Invalid ident ' . $Ident);
                }
        }
    }

    // ------------------------------------------------------------------ öffentliche Funktionen (VDM10_*)

    public function UnlockDoor(int $Door): bool
    {
        if ($Door < 1 || $Door > max(1, min(2, $this->ReadPropertyInteger('DoorCount')))) {
            throw new InvalidArgumentException(sprintf($this->Translate('Door opener %d is not set up'), $Door));
        }
        $door = $Door;
        $ok = $this->isapi()->door($door, 'open');
        if ($ok) {
            $this->SetValue('DOOR' . $door, true);
            $this->SetTimerInterval('DoorReset', self::DOOR_PULSE_MS);
        } else {
            $this->LogMessage($this->Translate('Door opener failed'), KL_WARNING);
        }
        return $ok;
    }

    public function TakeSnapshot(): bool
    {
        $jpeg = $this->isapi()->picture($this->ReadPropertyInteger('SnapshotChannel'));
        if ($jpeg === '') {
            return false;
        }
        $this->storeSnapshot($jpeg);
        return true;
    }

    public function UpdateCallStatus(): string
    {
        $status = $this->isapi()->callStatus();
        if ($status === '') {
            if (!$this->GetValue('STREAM_CONNECTED')) {
                $this->SetValue('ONLINE', false);
            }
            return '';
        }
        $this->SetValue('ONLINE', true);
        $map = ['idle' => self::CALL_IDLE, 'ring' => self::CALL_RING, 'onCall' => self::CALL_ON_CALL];
        $new = $map[$status] ?? self::CALL_IDLE;
        $old = $this->GetValue('CALL_STATUS');
        if ($new === self::CALL_RING && $old !== self::CALL_RING) {
            $this->ring('poll');
        }
        if ($new === self::CALL_RING && $this->GetBuffer('DeviceRing') !== '1') {
            $this->callerButton('call'); // Ruf aufgebaut: jetzt steht der Anrufer fest
        }
        $this->SetValue('CALL_STATUS', $new);
        // Die Klingel erlischt vorzeitig nur, wenn die Türstation selbst einen Ruf gemeldet hat
        // und dieser beendet ist. Der Tastendruck (Alarmeingang) kommt im Stream einige Sekunden
        // vor dem Rufaufbau an; in dieser Lücke meldet callStatus noch "idle".
        if ($new === self::CALL_RING) {
            $this->SetBuffer('DeviceRing', '1');
        } elseif ($new === self::CALL_IDLE && $this->GetBuffer('DeviceRing') === '1') {
            $this->SetBuffer('DeviceRing', '');
            $this->ResetRing();
        }
        return $status;
    }

    /** Generischer ISAPI-Durchgriff für Skripte; liefert den Antwortrumpf. */
    public function Request(string $Method, string $Path, string $Body = ''): string
    {
        if (!$this->ReadPropertyBoolean('HoldOpenEnabled') && strtoupper($Method) !== 'GET' && VDM10Isapi::containsHoldOpen($Path . ' ' . $Body)) {
            throw new RuntimeException($this->Translate('“Permanently open” is disabled in the instance configuration.'));
        }
        $r = $this->isapi()->request(strtoupper($Method), $Path, $Body);
        return $r['body'] !== '' ? $r['body'] : VDM10Isapi::errorText($r);
    }

    public function TestConnection(): string
    {
        try {
            $info = $this->isapi()->deviceInfo();
            $this->applyDeviceInfo($info);
            return sprintf($this->Translate("Connected\nModel: %s\nFirmware: %s %s\nSerial number: %s"),
                $info['model'] ?? '?', $info['firmwareVersion'] ?? '', $info['firmwareReleasedDate'] ?? '', $info['serialNumber'] ?? '');
        } catch (Throwable $e) {
            return $this->Translate('Connection failed') . ': ' . $e->getMessage();
        }
    }

    public function Poll(): void
    {
        $this->UpdateCallStatus();
        if (!$this->GetValue('STREAM_CONNECTED') && $this->GetValue('ONLINE')) {
            $this->pollAccessEvents();
        }
    }

    public function ResetRing(): void
    {
        $this->SetTimerInterval('RingReset', 0);
        $this->SetValue('DOORBELL', false);
        // Ruft die Türstation noch (bis ~60 s), bleibt der Rufstatus „Klingelt“ – sonst meldet die
        // nächste Abfrage einen Wechsel frei→klingelt und damit ein zweites Klingeln.
        if ($this->GetValue('CALL_STATUS') === self::CALL_RING && $this->GetBuffer('DeviceRing') !== '1') {
            $this->SetValue('CALL_STATUS', self::CALL_IDLE);
        }
    }

    public function ResetMotion(): void
    {
        $this->SetTimerInterval('MotionReset', 0);
        $this->SetValue('MOTION', false);
    }

    public function ResetDoors(): void
    {
        $this->SetTimerInterval('DoorReset', 0);
        $this->SetValue('DOOR1', false);
        if ($this->ReadPropertyInteger('DoorCount') >= 2) {
            $this->SetValue('DOOR2', false);
        }
    }

    // ------------------------------------------------------------------ intern

    private function isapi(): VDM10Isapi
    {
        $base = ($this->ReadPropertyBoolean('UseHttps') ? 'https' : 'http') . '://' . $this->ReadPropertyString('Host') . ':' . $this->ReadPropertyInteger('Port');
        return (new VDM10Isapi($base, $this->ReadPropertyString('Username'), $this->ReadPropertyString('Password'),
            fn (string $topic, string $message) => $this->SendDebug($topic, $message, 0)))->blockHoldOpen(!$this->ReadPropertyBoolean('HoldOpenEnabled'));
    }

    private function refreshDeviceInfo(): void
    {
        try {
            $this->applyDeviceInfo($this->isapi()->deviceInfo());
            $this->SetStatus(IS_ACTIVE);
            $this->SetValue('ONLINE', true);
        } catch (Throwable $e) {
            $this->SendDebug('DeviceInfo', $e->getMessage(), 0);
            $this->SetStatus($e->getCode() === 401 ? self::STATUS_AUTH_FAILED : self::STATUS_UNREACHABLE);
            $this->SetValue('ONLINE', false);
        }
    }

    private function applyDeviceInfo(array $info): void
    {
        $this->WriteAttributeString('DeviceInfo', (string) json_encode($info));
        $this->SetSummary(trim(($info['model'] ?? '') . ' ' . ($info['firmwareVersion'] ?? '')) ?: $this->ReadPropertyString('Host'));
    }

    /** @param array{kind:string,label:string,major:int,minor:int,current:bool,name:string,door:int,time:string,raw:string} $e */
    private function handleEvent(array $e, string $source): void
    {
        if ($e['kind'] === VDM10Events::KIND_HEARTBEAT) {
            $this->SetValue('ONLINE', true);
            return;
        }
        $this->SendDebug('Event', sprintf('%s %s %s%s%s', $source, $e['kind'], $e['raw'], $e['name'] !== '' ? ' ' . $e['name'] : '', $e['current'] ? '' : ' (history)'), 0);
        if (!$e['current'] || $e['major'] === 3) {
            return; // Wiedergabe alter Ereignisse bzw. Bedienprotokoll (Anmeldungen, u. a. die eigenen Abrufe)
        }
        $this->SetValue('LAST_EVENT', $this->Translate($e['label']) . ' (' . $e['raw'] . ')');
        if ($this->securityModuleEvent($e)) {
            return;
        }
        switch ($e['kind']) {
            case VDM10Events::KIND_RING:
                $this->ring($source, $e['key'], $e['room']);
                break;
            case VDM10Events::KIND_UNLOCK:
                $door = $e['door'] >= 1 && $e['door'] <= $this->ReadPropertyInteger('DoorCount') ? $e['door'] : 1;
                $this->SetValue('DOOR' . $door, true);
                $this->SetTimerInterval('DoorReset', self::DOOR_PULSE_MS);
                $this->refreshDoorHold();
                if (!(@$this->GetIDForIdent('DOOR' . $door . '_HOLD') && $this->GetValue('DOOR' . $door . '_HOLD'))) {
                    $this->logEvent('unlock', ['door' => $door]); // „dauerhaft offen“ loggt der Schalter selbst
                }
                break;
            case VDM10Events::KIND_LOCK:
                $this->refreshDoorHold();
                break;
            case VDM10Events::KIND_ACCESS_OK:
            case VDM10Events::KIND_ACCESS_FAIL:
                // Bild zuerst (die Person steht noch vor der Kamera), ohne den Kachel-Snapshot zu ersetzen
                $jpeg = $this->ReadPropertyBoolean('SnapshotOnAccess') ? $this->isapi()->picture($this->ReadPropertyInteger('SnapshotChannel')) : '';
                $text = $this->Translate($e['label']);
                if ($e['kind'] === VDM10Events::KIND_ACCESS_FAIL) {
                    $text .= ' ' . $this->Translate('(denied)');
                }
                // Der Alert-Stream liefert keinen Namen; das Ereignisprotokoll der Türstation schon.
                [$name, $employee] = $this->accessInfo($e['major'], $e['minor']);
                $name = $e['name'] !== '' ? $e['name'] : $name;
                $this->SetValue('LAST_ACCESS', $name !== '' ? $text . ': ' . $name : $text);
                $this->SetValue('LAST_ACCESS_TIME', time());
                $this->logAccess($e, $name, $employee, $jpeg);
                break;
            case VDM10Events::KIND_MOTION:
                $this->SetValue('MOTION', true);
                $this->SetTimerInterval('MotionReset', max(1, $this->ReadPropertyInteger('MotionReset')) * 1000);
                break;
            case VDM10Events::KIND_TAMPER:
                $this->SetValue('TAMPER', true);
                break;
            case VDM10Events::KIND_OTHER:
                if ($e['major'] === 1 && $e['minor'] === 0x405) {
                    $this->SetValue('TAMPER', false);
                }
                break;
        }
    }

    private function ring(string $source, int $key = 0, int $room = 0): void
    {
        $now = time();
        $last = (int) $this->GetBuffer('RingTrigger');
        $this->SetBuffer('RingTrigger', (string) $now);
        if ($now - $last <= self::RING_DEDUPE_SECONDS) { // Folgemeldung desselben Besuchs: Klingel nur verlängern
            if ($this->GetValue('DOORBELL')) {
                $this->SetTimerInterval('RingReset', max(5, $this->ReadPropertyInteger('RingTimeout')) * 1000);
            }
            return;
        }
        $this->SendDebug('Ring', $source, 0);
        $this->SetValue('DOORBELL', true);
        $this->SetValue('LAST_RING', $now);
        if ($this->GetValue('CALL_STATUS') === self::CALL_IDLE) {
            $this->SetValue('CALL_STATUS', self::CALL_RING);
        }
        $this->SetTimerInterval('RingReset', max(5, $this->ReadPropertyInteger('RingTimeout')) * 1000);
        if ($this->ReadPropertyBoolean('SnapshotOnRing')) {
            $this->TakeSnapshot();
        }
        $this->logRing($source, $this->ringButton($key, $room));
        if ($key === 0 && $room === 0) {
            $this->callerButton($source);
        }
    }
}
