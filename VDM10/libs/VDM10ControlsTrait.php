<?php

declare(strict_types=1);

/**
 * Schaltbare Einstellungen der Türstation als Variablen: Tür dauerhaft offen, Bewegungserkennung
 * und Empfindlichkeit, Lautstärken, Klingelton, Tastenbeleuchtung; dazu SIP-Status und Firmware.
 * Schreiben immer als Lesen-Ändern-Zurückschreiben, damit unbekannte Felder erhalten bleiben.
 * Wird von VDM10 (module.php) als Trait eingebunden.
 */
trait VDM10ControlsTrait
{
    private const PATH_MOTION = '/ISAPI/System/Video/inputs/channels/1/motionDetection';
    private const PATH_AUDIO = '/ISAPI/System/Audio/AudioOut/channels/1';
    private const PATH_BACKLIGHT = '/ISAPI/VideoIntercom/SubModuleBacklight?format=json';
    private const PATH_WORK_STATUS = '/ISAPI/AccessControl/AcsWorkStatus?format=json';
    private const DOOR_STATUS_ALWAYS_OPEN = 2;
    private const BACKLIGHT_MODES = ['auto', 'on', 'off', 'custom'];

    /** In Create() aufrufen. */
    private function registerControlVariables(): void
    {
        $this->RegisterPropertyBoolean('HoldOpenEnabled', true);
        $this->RegisterVariableBoolean('DOOR1_HOLD', $this->Translate('Door 1 permanently open'), $this->holdPresentation(), 45);
        $this->EnableAction('DOOR1_HOLD');
        $this->RegisterVariableBoolean('MOTION_DETECTION', $this->Translate('Motion detection'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH, 'ICON_TRUE' => 'Motion', 'ICON_FALSE' => 'Motion', 'USAGE_TYPE' => 0,
        ], 140);
        $this->EnableAction('MOTION_DETECTION');
        $this->RegisterVariableInteger('MOTION_SENSITIVITY', $this->Translate('Motion sensitivity'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER, 'ICON' => 'Motion', 'MIN' => 1, 'MAX' => 6, 'STEP_SIZE' => 1, 'USAGE_TYPE' => 0,
        ], 150);
        $this->EnableAction('MOTION_SENSITIVITY');
        $this->RegisterVariableBoolean('RING_ENABLED', $this->Translate('Ringtone at the door station'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH, 'ICON_TRUE' => 'Speaker', 'ICON_FALSE' => 'Speaker', 'USAGE_TYPE' => 0,
        ], 160);
        $this->EnableAction('RING_ENABLED');
        foreach (['RING_VOLUME' => ['Ringtone volume', 170], 'TALK_VOLUME' => ['Speech volume', 180]] as $ident => [$caption, $pos]) {
            $this->RegisterVariableInteger($ident, $this->Translate($caption), [
                'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER, 'ICON' => 'Speaker', 'MIN' => 0, 'MAX' => 10, 'STEP_SIZE' => 1, 'USAGE_TYPE' => 2,
            ], $pos);
            $this->EnableAction($ident);
        }
        $this->RegisterVariableString('BACKLIGHT', $this->Translate('Button lighting'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION, 'ICON' => 'Bulb',
            'OPTIONS' => json_encode(array_map(fn (string $m): array => ['Value' => $m, 'Caption' => $this->Translate('backlight_' . $m),
                'IconValue' => '', 'IconActive' => false, 'Color' => -1], self::BACKLIGHT_MODES), JSON_UNESCAPED_UNICODE),
        ], 185);
        $this->EnableAction('BACKLIGHT');
        $sip = fn (string $v, string $c, int $color): array => ['Value' => $v, 'Caption' => $this->Translate($c), 'IconActive' => false, 'IconValue' => '',
            'ColorActive' => true, 'ColorValue' => $color, 'ContentColorActive' => false, 'ContentColorValue' => -1];
        $this->RegisterVariableString('SIP_STATUS', $this->Translate('SIP registration'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Telephone',
            'OPTIONS' => json_encode([$sip('connect', 'Registered', 0x00C000), $sip('unregistered', 'Not registered', 0xFF0000),
                $sip('notEnabled', 'Not enabled', 0x808080)], JSON_UNESCAPED_UNICODE),
        ], 135);
        $this->RegisterVariableString('FIRMWARE', $this->Translate('Firmware'), ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Information'], 195);
        $this->RegisterTimer('StatusRefresh', 0, 'VDM10_RefreshStatus($_IPS[\'TARGET\']);');
    }

    /** In ApplyChanges() aufrufen. */
    private function maintainControlVariables(bool $active): void
    {
        $two = $this->ReadPropertyInteger('DoorCount') >= 2;
        $this->MaintainVariable('DOOR2_HOLD', $this->Translate('Door 2 permanently open'), VARIABLETYPE_BOOLEAN, $this->holdPresentation(), 55, $two);
        // „Dauerhaft offen“ gesperrt: kein Schalter mehr, die Variable zeigt nur noch den Zustand der Türstation
        foreach ($two ? [1, 2] : [1] as $door) {
            if ($this->ReadPropertyBoolean('HoldOpenEnabled')) {
                $this->EnableAction('DOOR' . $door . '_HOLD');
            } else {
                $this->DisableAction('DOOR' . $door . '_HOLD');
            }
        }
        $this->SetTimerInterval('StatusRefresh', $active ? 60000 : 0);
        if ($active) {
            $this->RefreshStatus();
        }
    }

    /** Nur den Türzustand (dauerhaft offen) nachlesen, z. B. nach einem Öffnen/Verriegeln-Ereignis. */
    private function refreshDoorHold(): void
    {
        $ws = $this->json($this->isapi()->request('GET', self::PATH_WORK_STATUS, '', 5))['AcsWorkStatus'] ?? [];
        $this->applyMagneticStatus($ws);
        foreach ([1, 2] as $door) {
            if (@$this->GetIDForIdent('DOOR' . $door . '_HOLD') && isset($ws['doorStatus'][$door - 1])) {
                $this->SetValue('DOOR' . $door . '_HOLD', (int) $ws['doorStatus'][$door - 1] === self::DOOR_STATUS_ALWAYS_OPEN);
            }
        }
    }

    public function AnswerCall(): bool
    {
        return $this->isapi()->callSignal('answer');
    }

    public function RejectCall(): bool
    {
        return $this->isapi()->callSignal('reject');
    }

    public function HangUp(): bool
    {
        return $this->isapi()->callSignal('hangUp');
    }

    public function Reboot(): bool
    {
        return $this->isapi()->reboot();
    }

    /** Liest Türzustand, SIP, Bewegungserkennung, Audio, Beleuchtung und Erweiterungsmodule. */
    public function RefreshStatus(): void
    {
        $api = $this->isapi();
        try {
            $this->refreshDoorHold();
            $this->refreshSecurityModule();
            $ws = $this->json($api->request('GET', self::PATH_WORK_STATUS, '', 5))['AcsWorkStatus'] ?? [];
            if (isset($ws['voipStatus'])) {
                $this->SetValue('SIP_STATUS', (string) $ws['voipStatus']);
            }
            $motion = $api->request('GET', self::PATH_MOTION, '', 5)['body'];
            if (preg_match('#<enabled>(true|false)</enabled>#', $motion, $m)) {
                $this->SetValue('MOTION_DETECTION', $m[1] === 'true');
            }
            if (preg_match('#<sensitivityLevel>(\d+)</sensitivityLevel>#', $motion, $m)) {
                $this->SetValue('MOTION_SENSITIVITY', (int) $m[1]);
            }
            $audio = $api->request('GET', self::PATH_AUDIO, '', 5)['body'];
            foreach (['volume' => 'RING_VOLUME', 'talkVolume' => 'TALK_VOLUME'] as $tag => $ident) {
                if (preg_match('#<' . $tag . '>(\d+)</' . $tag . '>#', $audio, $m)) {
                    $this->SetValue($ident, (int) $m[1]);
                }
            }
            if (preg_match('#<RingEn>(true|false)</RingEn>#', $audio, $m)) {
                $this->SetValue('RING_ENABLED', $m[1] === 'true');
            }
            $light = $this->json($api->request('GET', self::PATH_BACKLIGHT, '', 5));
            if (isset($light['backlightMode'])) {
                $this->SetValue('BACKLIGHT', (string) $light['backlightMode']);
            }
            $info = json_decode($this->ReadAttributeString('DeviceInfo'), true);
            $this->SetValue('FIRMWARE', trim(($info['firmwareVersion'] ?? '') . ' ' . ($info['firmwareReleasedDate'] ?? '')));
            $this->refreshSubModules();
            $this->refreshDoNotDisturb();
            $this->refreshClock();
        } catch (Throwable $e) {
            $this->SendDebug('Status', $e->getMessage(), 0);
        }
    }

    /** RequestAction für die Einstellungs-Variablen; false = Ident gehört nicht hierher. */
    private function controlAction(string $Ident, mixed $Value): bool
    {
        switch ($Ident) {
            case 'DOOR1_HOLD':
            case 'DOOR2_HOLD':
                if (!$this->ReadPropertyBoolean('HoldOpenEnabled')) {
                    throw new RuntimeException($this->Translate('“Permanently open” is disabled in the instance configuration.'));
                }
                $door = (int) substr($Ident, 4, 1);
                if (!$this->isapi()->door($door, (bool) $Value ? 'alwaysOpen' : 'resume')) {
                    throw new RuntimeException($this->Translate('Door opener failed'));
                }
                break;
            case 'MOTION_DETECTION':
                $this->xmlEdit(self::PATH_MOTION, ['enabled' => (bool) $Value ? 'true' : 'false']);
                break;
            case 'MOTION_SENSITIVITY':
                $this->xmlEdit(self::PATH_MOTION, ['sensitivityLevel' => (string) max(1, min(6, (int) $Value))]);
                break;
            case 'RING_ENABLED':
                $this->xmlEdit(self::PATH_AUDIO, ['RingEn' => (bool) $Value ? 'true' : 'false']);
                break;
            case 'RING_VOLUME':
            case 'TALK_VOLUME':
                $this->xmlEdit(self::PATH_AUDIO, [$Ident === 'RING_VOLUME' ? 'volume' : 'talkVolume' => (string) max(0, min(10, (int) $Value))]);
                break;
            case 'BACKLIGHT':
                if (!in_array($Value, self::BACKLIGHT_MODES, true)) {
                    throw new InvalidArgumentException('Invalid mode ' . $Value);
                }
                $cfg = $this->json($this->isapi()->request('GET', self::PATH_BACKLIGHT));
                $cfg['backlightMode'] = $Value;
                $this->writeChecked('PUT', self::PATH_BACKLIGHT, (string) json_encode($cfg));
                break;
            default:
                return false;
        }
        $this->SetValue($Ident, $Value);
        return true;
    }

    /** XML lesen, Werte einzelner Elemente ersetzen, vollständig zurückschreiben. */
    private function xmlEdit(string $path, array $values): bool
    {
        $r = $this->isapi()->request('GET', $path);
        if ($r['code'] !== 200) {
            throw new RuntimeException(VDM10Isapi::errorText($r));
        }
        $xml = $r['body'];
        foreach ($values as $tag => $value) {
            $count = 0;
            $xml = (string) preg_replace('#<' . $tag . '>[^<]*</' . $tag . '>#', '<' . $tag . '>' . htmlspecialchars($value, ENT_XML1) . '</' . $tag . '>', $xml, 1, $count);
            if ($count !== 1) {
                throw new RuntimeException('Element ' . $tag . ' not found');
            }
        }
        return $this->writeChecked('PUT', $path, $xml);
    }

    /** Schreibt und prüft die Antwort. Liefert true, wenn die Türstation einen Neustart verlangt (statusCode 7). */
    private function writeChecked(string $method, string $path, string $body): bool
    {
        $r = $this->isapi()->request($method, $path, $body);
        if (VDM10Isapi::isOk($r)) {
            return false;
        }
        if (str_contains($r['body'], 'rebootRequired')) {
            $this->SendDebug('Write', $path . ': reboot required', 0);
            return true;
        }
        throw new RuntimeException(VDM10Isapi::errorText($r));
    }

    /** @return array<string,mixed> */
    private function json(array $result): array
    {
        $j = json_decode($result['body'], true);
        return is_array($j) ? $j : [];
    }

    private function holdPresentation(): array
    {
        return ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH, 'ICON_TRUE' => 'LockOpen', 'ICON_FALSE' => 'LockClosed', 'USE_ICON_FALSE' => true, 'USAGE_TYPE' => 0];
    }

    private function boolPresentation(string $icon, string $falseCaption, string $trueCaption, int $falseColor, int $trueColor): array
    {
        $opt = fn (bool $v, string $c, int $color): array => ['Value' => $v, 'Caption' => $this->Translate($c), 'IconActive' => false, 'IconValue' => '',
            'ColorActive' => true, 'ColorValue' => $color, 'ContentColorActive' => false, 'ContentColorValue' => -1];
        return ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => $icon,
            'OPTIONS' => json_encode([$opt(false, $falseCaption, $falseColor), $opt(true, $trueCaption, $trueColor)], JSON_UNESCAPED_UNICODE)];
    }

    private function doorPresentation(): array
    {
        return ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH, 'ICON_TRUE' => 'Door', 'ICON_FALSE' => 'Door', 'USE_ICON_FALSE' => false, 'USAGE_TYPE' => 2];
    }
}
